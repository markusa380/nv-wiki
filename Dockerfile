FROM mediawiki:1.40

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