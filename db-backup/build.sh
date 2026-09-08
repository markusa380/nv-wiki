#!/usr/bin/env bash
# cd so the build context is this directory regardless of the caller's cwd.
set -euo pipefail
cd "$(dirname "$0")"

docker build . --tag nv-wiki-db-backup
