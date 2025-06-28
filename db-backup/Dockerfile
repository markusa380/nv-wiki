# Use an official lightweight image with MySQL client and AWS CLI
FROM amazonlinux:2

RUN yum update -y && \
    yum install -y mysql awscli && \
    yum clean all

COPY backup_and_upload.sh /usr/local/bin/backup_and_upload.sh
RUN chmod +x /usr/local/bin/backup_and_upload.sh

ENTRYPOINT ["/usr/local/bin/backup_and_upload.sh"]
