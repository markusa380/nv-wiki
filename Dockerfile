FROM mediawiki:1.45

RUN apt-get update && \
  apt-get install -y curl unzip && \
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install MediaWiki extensions. Copy composer.local.json first to allow caching of the layer.
COPY mediawiki/composer.local.json /var/www/html/
RUN composer update --no-dev
# Extensions downloaded from VCS have incorrect casing.
RUN mv /var/www/html/extensions/Contributionscores /var/www/html/extensions/ContributionScores
RUN mv /var/www/html/extensions/Discordauth /var/www/html/extensions/DiscordAuth
RUN mv /var/www/html/extensions/Hitcounters /var/www/html/extensions/HitCounters
RUN mv /var/www/html/extensions/Pluggableauth /var/www/html/extensions/PluggableAuth
RUN mv /var/www/html/extensions/Templatestyles /var/www/html/extensions/TemplateStyles
RUN mv /var/www/html/extensions/Uploadwizard /var/www/html/extensions/UploadWizard
RUN mv /var/www/html/extensions/Wsoauth /var/www/html/extensions/WSOAuth

# Run composer update again to install includes from extensions.
RUN composer update --no-dev

COPY mediawiki /var/www/html

# Exclude /server-status from the catch-all rewrite so mod_status is reachable.
COPY apache/short-url.conf /etc/apache2/conf-available/short-url.conf
COPY apache/mpm-tuning.conf /etc/apache2/conf-enabled/mpm-tuning.conf
COPY apache/no-php-in-writable-dirs.conf /etc/apache2/conf-enabled/no-php-in-writable-dirs.conf
COPY apache/remote-ip.conf /etc/apache2/conf-enabled/remote-ip.conf
RUN a2enmod remoteip

# zz- so that it loads after the image's own ini files.
COPY php/errors.ini /usr/local/etc/php/conf.d/zz-errors.ini
COPY php/apcu.ini /usr/local/etc/php/conf.d/zz-apcu.ini

# Samples aggregate resource usage for Special:ServerStats.
COPY apache/stats-sampler.sh /usr/local/bin/stats-sampler.sh
RUN chmod +x /usr/local/bin/stats-sampler.sh

# Entrypoint for the jobrunner service, which uses this image.
COPY jobrunner/jobrunner.sh /usr/local/bin/jobrunner.sh
RUN chmod +x /usr/local/bin/jobrunner.sh

# Deliberately outside /var/www/html: anything in the document root is
# fetchable, and a direct request to a PHP file there returns a fatal error
# that discloses absolute paths.
COPY local-extensions /var/www/local-extensions

# Run the schema update on startup before handing off to Apache.
RUN chmod +x /var/www/html/docker-entrypoint.sh

# The base image gives the web root to www-data with mode 1777, so PHP could
# change the wiki's own code. Root owns it now. www-data can write only to
# images/ and cache/, where PHP doesn't run (no-php-in-writable-dirs.conf).
RUN chown -R root:root /var/www/html && \
  chmod -R u=rwX,go=rX /var/www/html && \
  chmod 755 /var/www/html && \
  chown www-data:www-data /var/www/html/images /var/www/html/cache
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]