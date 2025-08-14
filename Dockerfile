FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    build-base \
    autoconf \
    mysql-client \
    git \
    curl \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    icu-dev \
    libxml2-dev \
    oniguruma-dev \
    gmp-dev \
    openssl-dev \
    zlib-dev \
    && rm -rf /var/cache/apk/*

RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring

COPY ./php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY ./opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

COPY ./src/index.php /var/www/index.php

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]