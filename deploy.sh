#!/usr/bin/env bash
# Builds every locally-built image and brings the whole stack to that version.
#
# docker stack deploy alone does not roll out image changes. The compose file
# pins images by tag, and with no registry to supply a digest Swarm sees no
# difference, so a rebuilt :latest leaves running tasks on the old image. The
# forced update below is what replaces them.

set -euo pipefail

cd "$(dirname "$0")"

STACK=wiki
# Repositories built from this repo. Registry images such as mariadb are left
# to stack deploy; restarting the database on every deploy has a cost.
LOCAL_IMAGES="nv-wiki nv-wiki-db-backup"

echo "==> Building images"
./build.sh
./db-backup/build.sh

echo "==> Deploying stack: $STACK"
docker stack deploy "$STACK" --compose-file docker-compose.yaml

echo "==> Rolling out new images"
mapfile -t SERVICES < <(docker stack services "$STACK" --format '{{.Name}} {{.Image}}')
for entry in "${SERVICES[@]}"; do
  name="${entry%% *}"
  image="${entry#* }"
  case " $LOCAL_IMAGES " in
    *" ${image%%:*} "*)
      # Blocks until the service converges, so the script exits only when the
      # stack actually runs the images built above.
      echo "  $name"
      docker service update --force --quiet "$name" >/dev/null
      ;;
  esac
done

echo "==> Done"
docker stack services "$STACK" --format '  {{.Name}}  {{.Replicas}}  {{.Image}}'
