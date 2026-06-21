#!/bin/bash
set -e

# Wait for the database to accept connections. In Swarm, depends_on does not
# wait for readiness, so a fresh deploy can race the database container.
echo "Waiting for database..."
until php maintenance/run.php sql --query "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

# Apply any pending schema upgrades. Idempotent; a no-op when up to date.
# This replaces the previous manual step of running update.php by hand after
# bumping the MediaWiki version.
echo "Running MediaWiki schema update..."
php maintenance/run.php update --quick

# Hand off to the image's original entrypoint (which runs CMD, apache2-foreground).
exec docker-php-entrypoint "$@"
