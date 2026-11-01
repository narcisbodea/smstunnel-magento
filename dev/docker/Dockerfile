# PHP-FPM image for the local Magento test stack (dev only, not part of the package).
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath ftp gd intl pdo_mysql soap sockets xsl zip opcache @composer \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip procps \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-magento.ini

RUN mkdir -p /var/www/html /composer-cache && chown -R www-data:www-data /var/www/html /composer-cache

ENV COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html
