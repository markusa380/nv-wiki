# Night Vision Wiki

MediaWiki for nv-intl.com, with a job runner and a daily database backup to
S3. Two images are built from this repository:

* `nv-wiki` (`Dockerfile`): MediaWiki with its extensions. It also runs the
  job runner, with `jobrunner/jobrunner.sh` as entrypoint.
* `nv-wiki-db-backup` (`db-backup/`): dumps the database and uploads it to S3.

## Deployment

Every push to `main` deploys both images to the homelab:
`.github/workflows/deploy.yml` builds them and hands them to the shared
workflow in [homelab-ci](https://github.com/markusa380/homelab-ci), which
pushes them to the homelab's registry and rolls them out. On startup,
`mediawiki/docker-entrypoint.sh` runs the schema update.

The production configuration (MariaDB, secrets, resource limits, the route for
`nv-intl.com` and the backup schedule) is in the private homelab-apps
repository, `modules/apps/wiki.nix`. `LocalSettings.php` reads the secrets from
files in `/run/secrets`.

To build an image locally, run `./build.sh` or `./db-backup/build.sh`.

## Debugging

This is not a full list of debugging tools, it needs further elaboration.

### Apache status

```sh
kubectl -n wiki exec deploy/mediawiki -- curl -s 127.0.0.1:80/server-status
```

`mod_status` is restricted to `Require local`, so it is only reachable from
inside the container. Append `?auto` for a machine-readable summary.

Useful when the wiki is unreachable while Kubernetes still reports the pod as
running.

`BusyWorkers` and `IdleWorkers` show whether the worker pool is exhausted. The
per-worker table's `SS` column gives seconds spent in the current request; for
reference, warm page loads measure around 0.2-0.9s, so large values there mark
requests that are not progressing.