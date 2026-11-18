FROM php:8.5-fpm-alpine@sha256:951fb5b1780219b81d5daffa4cebef8e0077c537f3893d9790a5dfcd66ca334d

# System dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    openldap-dev \
    linux-headers \
    nodejs \
    npm

# PHP extensions (opcache: da PHP 8.5 è nel motore, non si installa)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        ldap

# Redis extension: dal sorgente con checksum come nel Dockerfile di prod (pecl con PHP 8.5 falliva a tratti)
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5
RUN apk add --no-cache $PHPIZE_DEPS \
    && docker-php-source extract \
    && curl -fsSL "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz" -o /tmp/redis.tgz \
    && echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c - \
    && tar xzf /tmp/redis.tgz -C /tmp \
    && mv "/tmp/redis-${PHPREDIS_VERSION}" /usr/src/php/ext/redis \
    && rm /tmp/redis.tgz /tmp/package.xml \
    && docker-php-ext-install redis \
    && docker-php-source delete \
    && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

# PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Non-root user per sicurezza
RUN addgroup -g 1000 -S www && adduser -u 1000 -S www -G www
RUN mkdir -p backups && chown www:www backups
USER www

EXPOSE 9000
CMD ["php-fpm"]
