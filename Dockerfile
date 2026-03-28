############################################
# Stage 1: Builder
# Installs extensions, compiles assets, runs composer
############################################
FROM php:8.3-fpm-alpine AS builder

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
    linux-headers \
    nodejs \
    npm \
    $PHPIZE_DEPS

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
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
    && npm ci --no-audit \
    && npm run build \
    && rm -rf node_modules

############################################
# Stage 2: PHP-FPM production image
# Target: app
############################################
FROM php:8.3-fpm-alpine AS app

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Runtime libraries needed by extensions
RUN apk add --no-cache \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    libzip \
    oniguruma \
    icu-libs

# Copy compiled extensions from builder
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY docker/php/php.ini     /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Non-root user
RUN addgroup -g 1000 -S www && adduser -u 1000 -S www -G www

WORKDIR /var/www/html
COPY --from=builder /var/www/html .

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

# Static assets compiled in the builder stage
COPY --from=builder /var/www/html/public /var/www/html/public

EXPOSE 80 443
