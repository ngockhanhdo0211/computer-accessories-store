# syntax=docker/dockerfile:1

FROM composer:2 AS composer_deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --no-scripts

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY tokens.css ./tokens.css
COPY resources ./resources
COPY public ./public
COPY --from=composer_deps /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
    ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

FROM php:8.2-apache-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data artisan composer.json ./
COPY --from=composer_deps --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache-ports.conf /etc/apache2/ports.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/render-start.sh /usr/local/bin/render-start

RUN chmod 0755 /usr/local/bin/render-start \
    && install -d -o www-data -g www-data \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs

ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["render-start"]
