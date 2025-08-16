FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    nginx \
    && docker-php-ext-install pdo_pgsql pcntl opcache

RUN apk add --no-cache \
    git \
    make \
    gcc \
    musl-dev \
    openssl-dev \
    zlib-dev \
    libsodium-dev \
    autoconf \
    && rm -rf /var/cache/apk/*

# Instala a extensão phpredis via PECL
# O comando 'docker-php-ext-install' é um helper do Docker para PHP
# Alternativamente, você pode usar 'pecl install redis' e depois 'docker-php-ext-enable redis'
RUN pecl install redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis


COPY ./php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY ./opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

COPY ./src/index.php /var/www/index.php

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]