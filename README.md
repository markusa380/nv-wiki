# Night Vision Wiki Docker Stack

* Run `./deploy.sh` to build every image and bring the whole stack up to date.
  This is the only command needed for a normal deploy. It also restarts the
  database backup service, which takes a backup immediately.
* Run `./build.sh` or `./db-backup/build.sh` to build a single image without
  deploying.

`docker stack deploy` on its own does not roll out image changes: the compose
file pins images by tag and there is no registry digest to compare, so running
tasks stay on the old image. `deploy.sh` forces the update afterwards.

## Prerequisites

* Connected to a Docker Daemon
* Deployed traefik reverse proxy
    * in `servernet` Docker swarm network
    * with `mediawiki` router
        * with `websecure` entrypoint on port 443
        * with `myresolver` certificate resolver
* All secrets configured (see [docker-compose.yaml](./docker-compose.yaml)).

## Debugging

This is not a full list of debugging tools, it needs further elaboration.

### Apache status

```sh
docker exec $(docker ps --filter name=wiki_mediawiki -q) curl -s 127.0.0.1:80/server-status
```

`mod_status` is restricted to `Require local`, so it is only reachable from
inside the container. Append `?auto` for a machine-readable summary.

Useful when the wiki is unreachable while Docker still reports the container
as running.

`BusyWorkers` and `IdleWorkers` show whether the worker pool is exhausted. The
per-worker table's `SS` column gives seconds spent in the current request; for
reference, warm page loads measure around 0.2-0.9s, so large values there mark
requests that are not progressing.