#!/bin/bash
set -e

MYSQL_HOST=database
MYSQL_USER=wiki
MYSQL_PASSWORD=$(cat /run/secrets/wiki-password)
MYSQL_DATABASE=wikidb
S3_BUCKET=night-vision-wiki-backups
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
FILENAME="${MYSQL_DATABASE}_$TIMESTAMP.sql.gz"
DUMP_FILE="/tmp/${FILENAME}"

mysqldump --single-transaction \
    -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" \
    "$MYSQL_DATABASE" \
    | gzip > "$DUMP_FILE"

S3_PATH="s3://$S3_BUCKET/$FILENAME"

aws s3 cp "$DUMP_FILE" "$S3_PATH"

echo "Backup uploaded to $S3_PATH"
