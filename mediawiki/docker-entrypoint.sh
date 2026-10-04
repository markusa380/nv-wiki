#!/bin/bash
set -e

# Wait for the database to accept connections. It may still be starting, e.g.
# on a fresh deploy or after both pods restarted.
echo "Waiting for database..."
until php maintenance/run.php sql --query "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

# Apply any pending schema upgrades. Idempotent; a no-op when up to date.
# This replaces the previous manual step of running update.php by hand after
# bumping the MediaWiki version.
echo "Running MediaWiki schema update..."
php maintenance/run.php update --quick

# Sample resource usage for Special:ServerStats. Runs here rather than as a
# sidecar because the cgroup files describe this container and mod_status is
# restricted to Require local.
echo "Starting stats sampler..."
/usr/local/bin/stats-sampler.sh &

# Hand off to the image's original entrypoint (which runs CMD, apache2-foreground).
exec docker-php-entrypoint "$@"
