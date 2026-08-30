# Night Vision Wiki Docker Stack

* Run `./build.sh` to build a new version of the mediawiki image.
* Run `./deploy.sh` to deploy/update the entire stack.
* Run `./update.sh` to update the mediawiki service to the latest image.
* Run `./db-backup/build.sh` to build a new version of the database backup image.
* Run `./db-backup/update.sh` to update the database backup service to the latest image.

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