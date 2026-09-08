#!/usr/bin/env bash
# cd so the build context is the repo root regardless of the caller's cwd.
set -euo pipefail
cd "$(dirname "$0")"

docker build . --tag nv-wiki
