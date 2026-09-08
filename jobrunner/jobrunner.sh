#!/bin/bash
# Runs the MediaWiki job queue for the jobrunner service.
# Separate container: SMW maintenance jobs only do work in command-line mode,
# and jobs need a memory limit separate from the web container's 500M.
# Outside /var/www/html: files in the docroot are web-served verbatim.

set -u

cd /var/www/html || exit 1

BATCH="${JOBRUNNER_BATCH:-50}"
IDLE="${JOBRUNNER_IDLE:-10}"
MEM="${JOBRUNNER_MEMORY_LIMIT:-256M}"
HEARTBEAT="${JOBRUNNER_HEARTBEAT:-/tmp/jobrunner-heartbeat}"

# Swarm depends_on does not wait for readiness.
echo "Waiting for database..."
until php maintenance/run.php sql --query "SELECT 1" >/dev/null 2>&1; do
  sleep 2
done

# No update.php here. Schema migration belongs to the web container;
# concurrent runs are unsafe.

echo "Starting job runner (batch=$BATCH, idle=${IDLE}s, memory-limit=$MEM)"
touch "$HEARTBEAT"

while true; do
  # One process per batch, not runJobs --wait. PHP does not return heap to the
  # kernel, so a long-lived process keeps its high-water mark.
  if ! php maintenance/run.php runJobs --maxjobs "$BATCH" --memory-limit "$MEM"; then
    # Continue on failure. One bad job must not crashloop the container.
    echo "warning: runJobs exited non-zero, continuing"
  fi

  # Health check reads this mtime. Stale means wedged, and Swarm replaces the
  # task.
  touch "$HEARTBEAT"

  sleep "$IDLE"
done
