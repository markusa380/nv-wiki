# Debian, because Amazon Linux 2 is end of life. Trixie's mariadb-client is
# 11.8, the same version as the server.
FROM debian:trixie-slim

RUN apt-get update && \
    apt-get install -y --no-install-recommends mariadb-client awscli ca-certificates && \
    rm -rf /var/lib/apt/lists/*

COPY backup_and_upload.sh /usr/local/bin/backup_and_upload.sh
RUN chmod +x /usr/local/bin/backup_and_upload.sh

ENTRYPOINT ["/usr/local/bin/backup_and_upload.sh"]
