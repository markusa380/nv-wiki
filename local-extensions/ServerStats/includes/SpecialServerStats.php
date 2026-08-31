<?php

namespace MediaWiki\Extension\ServerStats;

use SpecialPage;

/**
 * Renders aggregate CPU, memory and worker statistics as inline SVG.
 *
 * Data comes from the CSV ring buffer written by apache/stats-sampler.sh. The
 * page is deliberately public, so it only ever reads aggregates -- the sampler
 * never records client addresses, request URLs or the worker scoreboard.
 *
 * Charts are generated server-side. There is no JavaScript and no charting
 * library, which keeps the page cheap on a CPU-limited container and avoids
 * introducing a script surface on a page anyone can view.
 */
class SpecialServerStats extends SpecialPage {

	/** Chart viewBox and plot area, in user units. */
	private const VB_W = 760;
	private const VB_H = 190;
	private const PLOT_L = 54;
	private const PLOT_R = 658;
	private const PLOT_T = 18;
	private const PLOT_B = 148;

	/** Most points to draw; samples are averaged into this many buckets. */
	private const MAX_POINTS = 240;

	/** A gap wider than this many seconds breaks the line. */
	private const GAP_SECONDS = 300;

	public function __construct() {
		parent::__construct( 'ServerStats' );
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'wiki';
	}

	/** @inheritDoc */
	public function execute( $sub ) {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$out->addInlineStyle( $this->getStyles() );

		$series = $this->buildSeries( $this->readSamples() );
		$points = $series['points'];

		if ( count( $points ) < 2 ) {
			$out->addHTML( '<p>' . $this->msg( 'serverstats-nodata' )->escaped() . '</p>' );
			return;
		}

		// outputHeader() already emits serverstats-summary by convention.
		$out->addHTML( '<div class="ss-root">' );

		$out->addHTML( $this->renderChart(
			$points,
			'cpu',
			'ss-cpu',
			$this->msg( 'serverstats-cpu-title' )->text(),
			$this->msg( 'serverstats-cpu-subtitle' )
				->params( $this->getLanguage()->formatNum( round( $series['cpuLimit'], 2 ) ) )->text(),
			$series['cpuLimit'] > 0 ? $series['cpuLimit'] : $this->seriesMax( $points, 'cpu' ),
			2
		) );

		$out->addHTML( $this->renderChart(
			$points,
			'mem',
			'ss-mem',
			$this->msg( 'serverstats-memory-title' )->text(),
			$this->msg( 'serverstats-memory-subtitle' )
				->params( $this->getLanguage()->formatNum( round( $series['memLimit'] ) ) )->text(),
			$series['memLimit'] > 0 ? $series['memLimit'] : $this->seriesMax( $points, 'mem' ),
			0
		) );

		$out->addHTML( $this->renderStackedChart(
			$points,
			$this->msg( 'serverstats-workers-title' )->text(),
			$this->msg( 'serverstats-workers-subtitle' )->text()
		) );

		$out->addHTML( '<p class="ss-note">' . $this->msg( 'serverstats-gap' )->escaped() . '</p>' );
		if ( $series['last'] ) {
			$out->addHTML( '<p class="ss-note">'
				. $this->msg( 'serverstats-updated' )
					->params( gmdate( 'Y-m-d H:i', $series['last'] ) )->escaped()
				. '</p>' );
		}
		$out->addHTML( '</div>' );
	}

	/**
	 * Reads the ring buffer.
	 *
	 * @return array[] Rows of [ts, cpuUsec, memBytes, busy, idle, cpuQuota, memLimit].
	 */
	private function readSamples(): array {
		$path = $this->getConfig()->get( 'ServerStatsFile' );
		if ( !is_readable( $path ) ) {
			return [];
		}

		$handle = fopen( $path, 'r' );
		if ( !$handle ) {
			return [];
		}

		$rows = [];
		while ( ( $fields = fgetcsv( $handle, 256 ) ) !== false ) {
			// The sampler may be mid-append; ignore anything short or unparseable.
			if ( count( $fields ) < 7 || !is_numeric( $fields[0] ) ) {
				continue;
			}
			$rows[] = [
				'ts' => (int)$fields[0],
				'cpuUsec' => (float)$fields[1],
				'memBytes' => (float)$fields[2],
				'busy' => (int)$fields[3],
				'idle' => (int)$fields[4],
				'cpuQuota' => (float)$fields[5],
				'memLimit' => (float)$fields[6],
			];
		}
		fclose( $handle );

		usort( $rows, static fn ( $a, $b ) => $a['ts'] <=> $b['ts'] );
		return $rows;
	}

	/**
	 * Derives per-interval rates and downsamples to at most MAX_POINTS.
	 *
	 * CPU is a cumulative counter, so each point needs its predecessor. A
	 * negative delta means the container restarted and the counter reset; a
	 * long gap means the sampler was not running. Both yield a null, which
	 * breaks the line rather than drawing a false interpolation across it.
	 */
	private function buildSeries( array $rows ): array {
		$points = [];
		$cpuLimit = 0.0;
		$memLimit = 0.0;

		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$prev = $rows[$i - 1];
			$cur = $rows[$i];
			$elapsed = $cur['ts'] - $prev['ts'];

			$cores = null;
			if ( $elapsed > 0 && $elapsed <= self::GAP_SECONDS && $cur['cpuUsec'] >= $prev['cpuUsec'] ) {
				$cores = ( $cur['cpuUsec'] - $prev['cpuUsec'] ) / ( $elapsed * 1e6 );
			}

			// A zero total means mod_status was unreachable, not an idle
			// server; a gap is honest where a zero would be a false dip.
			$workersRead = ( $cur['busy'] + $cur['idle'] ) > 0;

			$points[] = [
				'ts' => $cur['ts'],
				'cpu' => $cores,
				'mem' => $cur['memBytes'] / 1048576,
				'busy' => $workersRead ? (float)$cur['busy'] : null,
				'idle' => $workersRead ? (float)$cur['idle'] : null,
			];

			$cpuLimit = max( $cpuLimit, $cur['cpuQuota'] );
			$memLimit = max( $memLimit, $cur['memLimit'] / 1048576 );
		}

		return [
			'points' => $this->downsample( $points ),
			'cpuLimit' => $cpuLimit,
			'memLimit' => $memLimit,
			'last' => $rows ? end( $rows )['ts'] : 0,
		];
	}

	/** Averages adjacent points into buckets, preserving nulls as gaps. */
	private function downsample( array $points ): array {
		$n = count( $points );
		if ( $n <= self::MAX_POINTS ) {
			return $points;
		}

		// A bucket is a gap only if every sample in it was a gap.
		$mean = static function ( array $chunk, string $key ) {
			$values = array_values( array_filter(
				array_column( $chunk, $key ),
				static fn ( $v ) => $v !== null
			) );
			return $values ? array_sum( $values ) / count( $values ) : null;
		};

		$size = (int)ceil( $n / self::MAX_POINTS );
		$out = [];
		for ( $i = 0; $i < $n; $i += $size ) {
			$chunk = array_slice( $points, $i, $size );
			$out[] = [
				'ts' => $chunk[count( $chunk ) - 1]['ts'],
				'cpu' => $mean( $chunk, 'cpu' ),
				'mem' => $mean( $chunk, 'mem' ),
				'busy' => $mean( $chunk, 'busy' ),
				'idle' => $mean( $chunk, 'idle' ),
			];
		}
		return $out;
	}

	private function seriesMax( array $points, string $key ): float {
		$values = array_filter( array_column( $points, $key ), static fn ( $v ) => $v !== null );
		return $values ? (float)max( $values ) : 1.0;
	}

	// ---------------------------------------------------------------------
	// Chart scaffolding shared by both chart types. Only the marks differ
	// between them; axes, gridlines, hover bands and the figure wrapper are
	// identical, so they live here rather than in each renderer.
	// ---------------------------------------------------------------------

	/**
	 * Builds the projection closures and the gridline/tick markup for a chart.
	 *
	 * @return array [ $x, $y, string[] $svg ]
	 */
	private function buildFrame( array $points, float $yMax, int $decimals ): array {
		$ticks = $this->niceTicks( $yMax );
		$top = (float)end( $ticks );

		$tsMin = $points[0]['ts'];
		$span = max( 1, end( $points )['ts'] - $tsMin );

		$x = fn ( $ts ) => self::PLOT_L + ( ( $ts - $tsMin ) / $span ) * ( self::PLOT_R - self::PLOT_L );
		$y = fn ( $v ) => self::PLOT_B - ( $top > 0 ? min( $v / $top, 1 ) : 0 ) * ( self::PLOT_B - self::PLOT_T );

		// Gridlines and y ticks first, so the data sits above them.
		$svg = [];
		foreach ( $ticks as $t ) {
			$ty = $y( $t );
			$svg[] = sprintf(
				'<line class="ss-grid" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" />',
				self::PLOT_L, $ty, self::PLOT_R, $ty
			);
			$svg[] = sprintf(
				'<text class="ss-tick" x="%.1f" y="%.1f" text-anchor="end">%s</text>',
				self::PLOT_L - 8, $ty + 4,
				htmlspecialchars( $this->getLanguage()->formatNum( round( $t, $decimals ) ) )
			);
		}

		return [ $x, $y, $svg ];
	}

	/** The baseline plus five evenly spaced time labels. */
	private function buildAxis( array $points, callable $x ): array {
		$tsMin = $points[0]['ts'];
		$span = max( 1, end( $points )['ts'] - $tsMin );

		$svg = [ sprintf(
			'<line class="ss-axis" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" />',
			self::PLOT_L, self::PLOT_B, self::PLOT_R, self::PLOT_B
		) ];

		for ( $i = 0; $i < 5; $i++ ) {
			$ts = $tsMin + (int)round( $span * $i / 4 );
			$svg[] = sprintf(
				'<text class="ss-tick" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
				$x( $ts ), self::PLOT_B + 20, htmlspecialchars( gmdate( 'H:i', $ts ) )
			);
		}
		return $svg;
	}

	/**
	 * Transparent hover bands carrying a native tooltip, so the chart is
	 * inspectable without any script.
	 *
	 * @param callable $tooltip Receives a point, returns unescaped text, or
	 *   null to skip the point.
	 */
	private function buildHoverBands( array $points, callable $x, callable $tooltip ): array {
		$band = ( self::PLOT_R - self::PLOT_L ) / max( 1, count( $points ) );

		$svg = [];
		foreach ( $points as $p ) {
			$text = $tooltip( $p );
			if ( $text === null ) {
				continue;
			}
			$svg[] = sprintf(
				'<rect class="ss-hit" x="%.1f" y="%.1f" width="%.2f" height="%.1f"><title>%s</title></rect>',
				$x( $p['ts'] ) - $band / 2, self::PLOT_T, max( 1.0, $band ), self::PLOT_B - self::PLOT_T,
				htmlspecialchars( $text )
			);
		}
		return $svg;
	}

	/**
	 * Splits points into runs where every listed key is present, so gaps in
	 * the data stay gaps instead of being interpolated across.
	 *
	 * @param string[] $keys All must be non-null for a point to be included.
	 */
	private function splitSegments( array $points, array $keys ): array {
		$segments = [];
		$run = [];
		foreach ( $points as $p ) {
			$complete = true;
			foreach ( $keys as $key ) {
				if ( $p[$key] === null ) {
					$complete = false;
					break;
				}
			}
			if ( !$complete ) {
				if ( count( $run ) > 1 ) {
					$segments[] = $run;
				}
				$run = [];
				continue;
			}
			$run[] = $p;
		}
		if ( count( $run ) > 1 ) {
			$segments[] = $run;
		}
		return $segments;
	}

	/** The most recent point where every listed key is present. */
	private function lastComplete( array $points, array $keys ): ?array {
		foreach ( array_reverse( $points ) as $p ) {
			foreach ( $keys as $key ) {
				if ( $p[$key] === null ) {
					continue 2;
				}
			}
			return $p;
		}
		return null;
	}

	/** A direct label at the right edge, level with the value it names. */
	private function valueLabel( float $y, string $text ): string {
		return sprintf(
			'<text class="ss-value" x="%.1f" y="%.1f">%s</text>',
			self::PLOT_R + 10, $y + 4, htmlspecialchars( $text )
		);
	}

	/** Wraps the marks in the figure, caption and optional legend. */
	private function buildFigure( string $title, string $subtitle, string $legend, array $svg ): string {
		return '<figure class="ss-figure">'
			. '<figcaption class="ss-caption">'
			. '<span class="ss-title">' . htmlspecialchars( $title ) . '</span>'
			. '<span class="ss-subtitle">' . htmlspecialchars( $subtitle ) . '</span>'
			. '</figcaption>'
			. $legend
			. sprintf(
				'<svg class="ss-svg" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="none">',
				self::VB_W, self::VB_H, htmlspecialchars( $title )
			)
			. implode( '', $svg )
			. '</svg></figure>';
	}

	/** Rounds the axis top up to a clean number and returns evenly spaced ticks. */
	private function niceTicks( float $max ): array {
		if ( $max <= 0 ) {
			return [ 0.0, 1.0 ];
		}
		$raw = $max / 4;
		$mag = 10 ** floor( log10( $raw ) );
		$norm = $raw / $mag;
		$step = ( $norm <= 1 ? 1 : ( $norm <= 2 ? 2 : ( $norm <= 5 ? 5 : 10 ) ) ) * $mag;

		$ticks = [];
		for ( $v = 0.0; $v < $max + $step * 0.001; $v += $step ) {
			$ticks[] = $v;
		}
		if ( count( $ticks ) < 2 ) {
			$ticks[] = $step;
		}
		return $ticks;
	}

	// ---------------------------------------------------------------------
	// The two chart types.
	// ---------------------------------------------------------------------

	/**
	 * A single series as a line over an area wash. One series carries no
	 * legend -- the heading names what is plotted, so a one-swatch box would
	 * only restate it.
	 */
	private function renderChart(
		array $points,
		string $key,
		string $class,
		string $title,
		string $subtitle,
		float $yMax,
		int $decimals
	): string {
		[ $x, $y, $svg ] = $this->buildFrame( $points, $yMax, $decimals );

		foreach ( $this->splitSegments( $points, [ $key ] ) as $segment ) {
			$coords = [];
			foreach ( $segment as $p ) {
				$coords[] = sprintf( '%.1f,%.1f', $x( $p['ts'] ), $y( $p[$key] ) );
			}

			$svg[] = sprintf(
				'<path class="ss-area %s" d="M%.1f,%.1f L%s L%.1f,%.1f Z" />',
				$class,
				$x( $segment[0]['ts'] ), self::PLOT_B,
				implode( ' L', $coords ),
				$x( end( $segment )['ts'] ), self::PLOT_B
			);
			$svg[] = sprintf(
				'<polyline class="ss-line %s" points="%s" />',
				$class, implode( ' ', $coords )
			);
		}

		$svg = array_merge( $svg, $this->buildAxis( $points, $x ) );

		// The endpoint is the one value worth labelling directly.
		$last = $this->lastComplete( $points, [ $key ] );
		if ( $last !== null ) {
			$svg[] = sprintf(
				'<circle class="ss-dot %s" cx="%.1f" cy="%.1f" r="4" />',
				$class, $x( $last['ts'] ), $y( $last[$key] )
			);
			$svg[] = $this->valueLabel(
				$y( $last[$key] ),
				$this->getLanguage()->formatNum( round( $last[$key], $decimals ) )
			);
		}

		$svg = array_merge( $svg, $this->buildHoverBands( $points, $x,
			fn ( $p ) => $p[$key] === null ? null : sprintf(
				'%s UTC — %s',
				gmdate( 'H:i', $p['ts'] ),
				$this->getLanguage()->formatNum( round( $p[$key], $decimals ) )
			)
		) );

		return $this->buildFigure( $title, $subtitle, '', $svg );
	}

	/**
	 * Busy and idle workers stacked, since together they are the whole pool.
	 *
	 * Two series means a legend is mandatory -- identity must never rest on
	 * colour alone. The bands are separated by a 2px stroke in the surface
	 * colour rather than a border, so the gap does the separating.
	 */
	private function renderStackedChart( array $points, string $title, string $subtitle ): string {
		$totals = [];
		foreach ( $points as $p ) {
			if ( $p['busy'] !== null && $p['idle'] !== null ) {
				$totals[] = $p['busy'] + $p['idle'];
			}
		}

		[ $x, $y, $svg ] = $this->buildFrame( $points, $totals ? (float)max( $totals ) : 1.0, 0 );

		foreach ( $this->splitSegments( $points, [ 'busy', 'idle' ] ) as $segment ) {
			$busyPts = [];
			$totalPts = [];
			foreach ( $segment as $p ) {
				$busyPts[] = sprintf( '%.1f,%.1f', $x( $p['ts'] ), $y( $p['busy'] ) );
				$totalPts[] = sprintf( '%.1f,%.1f', $x( $p['ts'] ), $y( $p['busy'] + $p['idle'] ) );
			}

			// Idle band sits on top of the busy band.
			$svg[] = sprintf(
				'<polygon class="ss-fill ss-idle" points="%s %s" />',
				implode( ' ', $totalPts ),
				implode( ' ', array_reverse( $busyPts ) )
			);
			// Busy band runs from the baseline up.
			$svg[] = sprintf(
				'<polygon class="ss-fill ss-busy" points="%.1f,%.1f %s %.1f,%.1f" />',
				$x( $segment[0]['ts'] ), self::PLOT_B,
				implode( ' ', $busyPts ),
				$x( end( $segment )['ts'] ), self::PLOT_B
			);
			// Each band is bounded by a 2px line in its own colour -- the same
			// treatment the single-series charts use, so all three read at the
			// same weight. The lines separate the bands, so the washes need no
			// surface gap between them.
			$svg[] = sprintf(
				'<polyline class="ss-line ss-idle" points="%s" />', implode( ' ', $totalPts )
			);
			$svg[] = sprintf(
				'<polyline class="ss-line ss-busy" points="%s" />', implode( ' ', $busyPts )
			);
		}

		$svg = array_merge( $svg, $this->buildAxis( $points, $x ) );

		// Label busy only -- that is the number worth reading, and labelling
		// both bands would collide whenever either is thin.
		$last = $this->lastComplete( $points, [ 'busy', 'idle' ] );
		if ( $last !== null ) {
			$svg[] = sprintf(
				'<circle class="ss-dot ss-busy" cx="%.1f" cy="%.1f" r="4" />',
				$x( $last['ts'] ), $y( $last['busy'] )
			);
			$svg[] = $this->valueLabel(
				$y( $last['busy'] ),
				$this->getLanguage()->formatNum( round( $last['busy'] ) )
			);
		}

		$svg = array_merge( $svg, $this->buildHoverBands( $points, $x,
			fn ( $p ) => ( $p['busy'] === null || $p['idle'] === null ) ? null : sprintf(
				'%s UTC — %s %s, %s %s',
				gmdate( 'H:i', $p['ts'] ),
				$this->getLanguage()->formatNum( round( $p['busy'] ) ),
				$this->msg( 'serverstats-workers-busy' )->text(),
				$this->getLanguage()->formatNum( round( $p['idle'] ) ),
				$this->msg( 'serverstats-workers-idle' )->text()
			)
		) );

		$legend = '<div class="ss-legend">'
			. '<span class="ss-key"><span class="ss-swatch ss-busy"></span>'
			. $this->msg( 'serverstats-workers-busy' )->escaped() . '</span>'
			. '<span class="ss-key"><span class="ss-swatch ss-idle"></span>'
			. $this->msg( 'serverstats-workers-idle' )->escaped() . '</span>'
			. '</div>';

		return $this->buildFigure( $title, $subtitle, $legend, $svg );
	}

	/**
	 * Light and dark are separately chosen steps against their own surface,
	 * not an automatic inversion. Dark is declared under the OS media query,
	 * the theme toggle and MediaWiki's night mode class, so whichever the
	 * viewer has set wins.
	 */
	private function getStyles(): string {
		$dark = <<<CSS
			--ss-surface: #1a1a19;
			--ss-text: #ffffff;
			--ss-muted: #898781;
			--ss-grid: #2c2c2a;
			--ss-axis: #383835;
			--ss-border: rgba(255,255,255,0.10);
			--ss-cpu: #3987e5;
			--ss-mem: #d95926;
			--ss-busy: #008300;
			--ss-idle: #9085e9;
		CSS;

		return <<<CSS
		.ss-root {
			color-scheme: light;
			--ss-surface: #fcfcfb;
			--ss-text: #0b0b0b;
			--ss-muted: #898781;
			--ss-grid: #e1e0d9;
			--ss-axis: #c3c2b7;
			--ss-border: rgba(11,11,11,0.10);
			--ss-cpu: #2a78d6;
			--ss-mem: #eb6834;
			--ss-busy: #008300;
			--ss-idle: #4a3aa7;
			max-width: 100%;
		}
		\@media (prefers-color-scheme: dark) {
			:root:where(:not([data-theme="light"])) .ss-root { color-scheme: dark; $dark }
		}
		:root[data-theme="dark"] .ss-root { color-scheme: dark; $dark }
		html.skin-theme-clientpref-night .ss-root { color-scheme: dark; $dark }

		.ss-figure {
			margin: 0 0 1.5em 0;
			padding: 12px 12px 4px 12px;
			background: var(--ss-surface);
			border: 1px solid var(--ss-border);
			border-radius: 6px;
			overflow-x: auto;
		}
		.ss-caption { display: block; margin-bottom: 4px; }
		.ss-title {
			display: block;
			font-weight: 600;
			color: var(--ss-text);
		}
		.ss-subtitle {
			display: block;
			font-size: 0.85em;
			color: var(--ss-muted);
		}
		.ss-svg { display: block; width: 100%; height: 190px; min-width: 480px; }

		.ss-grid { stroke: var(--ss-grid); stroke-width: 1; }
		.ss-axis { stroke: var(--ss-axis); stroke-width: 1; }
		.ss-tick {
			fill: var(--ss-muted);
			font-size: 11px;
			font-variant-numeric: tabular-nums;
		}
		.ss-value {
			fill: var(--ss-text);
			font-size: 12px;
			font-weight: 600;
		}
		.ss-line { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
		.ss-area { stroke: none; }
		/* The 2px surface ring keeps the marker legible where it meets the line. */
		.ss-dot { stroke: var(--ss-surface); stroke-width: 2; }
		.ss-hit { fill: transparent; }

		.ss-line.ss-cpu { stroke: var(--ss-cpu); }
		.ss-area.ss-cpu { fill: var(--ss-cpu); opacity: 0.10; }
		.ss-dot.ss-cpu { fill: var(--ss-cpu); }
		.ss-line.ss-mem { stroke: var(--ss-mem); }
		.ss-area.ss-mem { fill: var(--ss-mem); opacity: 0.10; }
		.ss-dot.ss-mem { fill: var(--ss-mem); }

		/* Two series, so a legend is mandatory. Text stays in ink; the
		   swatch beside it carries the identity. */
		.ss-legend {
			display: flex;
			flex-wrap: wrap;
			gap: 16px;
			margin: 2px 0 6px 0;
			font-size: 0.85em;
			color: var(--ss-text);
		}
		.ss-key { display: inline-flex; align-items: center; gap: 6px; }
		.ss-swatch {
			width: 12px;
			height: 12px;
			border-radius: 2px;
			display: inline-block;
		}
		.ss-swatch.ss-busy { background: var(--ss-busy); }
		.ss-swatch.ss-idle { background: var(--ss-idle); }

		/* Same 10% wash as the single-series charts, so the stacked chart does
		   not read as heavier than the other two. */
		.ss-fill { stroke: none; }
		.ss-fill.ss-busy { fill: var(--ss-busy); opacity: 0.10; }
		.ss-fill.ss-idle { fill: var(--ss-idle); opacity: 0.10; }
		.ss-line.ss-busy { stroke: var(--ss-busy); }
		.ss-line.ss-idle { stroke: var(--ss-idle); }
		.ss-dot.ss-busy { fill: var(--ss-busy); }

		.ss-note { font-size: 0.85em; color: var(--ss-muted); }
		CSS;
	}
}
