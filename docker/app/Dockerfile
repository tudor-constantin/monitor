# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89

FROM composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer-binary

FROM php:8.4.23-fpm-alpine@sha256:913ddd6934a805429618a16aa36da47cd8a8aec8b2f111c294936ba4003fded6 AS php-base

RUN apk add --no-cache \
        curl=8.21.0-r0 \
        icu-libs=78.1-r0 \
        libzip=1.11.4-r2 \
        nginx=1.30.4-r1 \
        oniguruma=6.9.10-r0 \
        supervisor=4.3.0-r1 \
    && apk add --no-cache --virtual .build-dependencies \
        autoconf=2.73-r0 \
        dpkg-dev=1.23.7-r0 \
        dpkg=1.23.7-r0 \
        file=5.47-r2 \
        g++=15.2.0-r5 \
        gcc=15.2.0-r5 \
        musl-dev=1.2.6-r2 \
        make=4.4.1-r4 \
        pkgconf=2.5.1-r0 \
        re2c=4.5.1-r0 \
        icu-dev=78.1-r0 \
        libzip-dev=1.11.4-r2 \
        linux-headers=7.0.0-r1 \
        oniguruma-dev=6.9.10-r0 \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-dependencies \
    && rm -rf /tmp/pear

FROM php-base AS dependencies

COPY --from=composer-binary /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY app/composer.json app/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY app .
RUN composer dump-autoload \
    --classmap-authoritative \
    --no-dev \
    --no-interaction

FROM node:22.23.2-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32 AS assets

WORKDIR /app

COPY app/package.json app/package-lock.json ./
RUN npm ci

COPY app/resources ./resources
COPY app/public ./public
COPY app/vite.config.js ./
COPY --from=dependencies /app/vendor ./vendor

RUN npm run build

FROM php-base AS runtime

WORKDIR /var/www/html

COPY --from=dependencies --chown=www-data:www-data /app ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY docker/app/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/monitor.ini
COPY docker/app/supervisord.conf /etc/supervisor/conf.d/monitor.conf
COPY docker/app/start-container /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/start-container"]
