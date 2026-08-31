#!/bin/bash
# Appends one sample per interval to a CSV ring buffer for Special:ServerStats.
#
# Runs inside the mediawiki container because both sources are local to it:
# the cgroup files describe this container, and mod_status is Require local.
# Only aggregates are recorded -- never the scoreboard, client IPs or request
# URLs, since the special page renders this publicly.
#
# CPU is stored as the raw cumulative cgroup counter; the special page derives
# rates from consecutive samples. The counter resets when the container
# restarts, which the page detects as a negative delta.

set -u

STATS_DIR="${STATS_DIR:-/data/stats}"
STATS_FILE="$STATS_DIR/samples.csv"
INTERVAL="${STATS_INTERVAL:-60}"
# 24h of samples at the default interval.
MAX_SAMPLES="${STATS_MAX_SAMPLES:-1440}"

mkdir -p "$STATS_DIR"

read_metric() {
  # $1 = file, $2 = key. cpu.stat is "key value" per line; memory.current is a
  # bare number, so a missing key yields the whole file's first field.
  if [ -n "${2:-}" ]; then
    awk -v k="$2" '$1 == k { print $2; exit }' "$1" 2>/dev/null
  else
    awk 'NR == 1 { print $1; exit }' "$1" 2>/dev/null
  fi
}

while true; do
  # Sleep first. A sample taken at t=0 catches the container mid-startup:
  # Apache is not listening yet, so workers read 0, and memory has not grown
  # to its working set. That point is honest but misleading, and it is the
  # one the charts label as current.
  sleep "$INTERVAL"

  ts=$(date +%s)
  cpu=$(read_metric /sys/fs/cgroup/cpu.stat usage_usec)
  mem=$(read_metric /sys/fs/cgroup/memory.current)
  quota=$(awk '{ print ($1 == "max") ? 0 : $1 / $2 }' /sys/fs/cgroup/cpu.max 2>/dev/null)
  limit=$(awk '$1 == "max" { print 0; exit } { print $1 }' /sys/fs/cgroup/memory.max 2>/dev/null)

  busy=""; idle=""
  status=$(curl -sf --max-time 5 '127.0.0.1:80/server-status?auto' 2>/dev/null) && {
    busy=$(printf '%s\n' "$status" | awk -F': ' '/^BusyWorkers/ { print $2; exit }')
    idle=$(printf '%s\n' "$status" | awk -F': ' '/^IdleWorkers/ { print $2; exit }')
  }

  # Skip the sample rather than write a partial row.
  if [ -n "$cpu" ] && [ -n "$mem" ]; then
    printf '%s,%s,%s,%s,%s,%s,%s\n' \
      "$ts" "$cpu" "$mem" "${busy:-0}" "${idle:-0}" "${quota:-0}" "${limit:-0}" \
      >> "$STATS_FILE"

    # Trim to the ring size. Rewrite via a temp file so a concurrent read
    # never sees a truncated file.
    lines=$(wc -l < "$STATS_FILE" 2>/dev/null || echo 0)
    if [ "$lines" -gt "$MAX_SAMPLES" ]; then
      tail -n "$MAX_SAMPLES" "$STATS_FILE" > "$STATS_FILE.tmp" &&
        mv "$STATS_FILE.tmp" "$STATS_FILE"
    fi
  fi
done
