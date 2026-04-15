# syntax=docker/dockerfile:1.7

############################################
# Stage 0: php-extensions
# Compila PHP extensions isolato dal codice app.
# Rebuild solo se cambia PHP version o lista extension.
############################################
FROM php:8.3-fpm-alpine AS php-extensions

# Layer 1: runtime libs — quasi mai cambia, cache molto stabile
RUN --mount=type=cache,id=apk-${TARGETARCH},target=/var/cache/apk \
    ln -sf /var/cache/apk /etc/apk/cache \
    && apk add \
        libpng libjpeg-turbo libwebp freetype libzip oniguruma icu-libs \
        git curl zip unzip

# Layer 2: compile deps + build extensions + cleanup in singolo layer
# (PHPIZE_DEPS e *-dev non devono finire nel layer finale)
RUN --mount=type=cache,id=apk-${TARGETARCH},target=/var/cache/apk \
    apk add \
        $PHPIZE_DEPS linux-headers \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        libzip-dev oniguruma-dev icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del \
        $PHPIZE_DEPS linux-headers \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        libzip-dev oniguruma-dev icu-dev

############################################
# Stage 1: builder
# Installa deps (composer+npm) prima del source code
# per massimizzare cache hit su ogni push.
############################################
FROM php-extensions AS builder

RUN --mount=type=cache,id=apk-${TARGETARCH},target=/var/cache/apk \
    apk add nodejs npm

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer deps: layer invalidato solo se composer.lock cambia
COPY composer.json composer.lock ./
RUN --mount=type=cache,id=composer-${TARGETARCH},target=/root/.composer/cache \
    composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress

# NPM deps: layer invalidato solo se package-lock.json cambia
COPY package.json package-lock.json ./
RUN --mount=type=cache,id=npm-${TARGETARCH},target=/root/.npm \
    npm ci --no-audit --cache /root/.npm

# Source code: COPY . . + npm build ripartono su ogni cambio sorgente,
# ma composer e npm ci sopra restano cached
COPY . .
RUN npm run build && rm -rf node_modules

############################################
# Stage 2: PHP-FPM production image
# Target: app — base pulita, no Node, no composer, no build tools
############################################
FROM php:8.3-fpm-alpine AS app

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Runtime libs identici a php-extensions Layer 1
RUN apk add --no-cache \
    libpng libjpeg-turbo libwebp freetype libzip oniguruma icu-libs

# Copia solo extensions compilate dallo stage dedicato
COPY --link --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --link --from=php-extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY docker/php/php.ini     /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

RUN addgroup -g 1000 -S www && adduser -u 1000 -S www -G www

WORKDIR /var/www/html
COPY --link --from=builder /var/www/html .

RUN chown -R www:www storage bootstrap/cache

USER www

EXPOSE 9000
CMD ["php-fpm"]

############################################
# Stage 3: Nginx + compiled static assets
# Target: web
############################################
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --link --from=builder /var/www/html/public /var/www/html/public

EXPOSE 80 443
