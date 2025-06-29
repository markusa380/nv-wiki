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