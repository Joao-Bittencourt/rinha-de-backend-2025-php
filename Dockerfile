FROM php:8.2-fpm-alpine

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

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]
