FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
    sqlite-libs \
    icu-libs \
    && docker-php-ext-install \
    intl \
    opcache \
    pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM base AS deps

# composer.lock* — the lock exists in created projects (composer create-project
# writes it) but not in the skeleton repo itself; the glob keeps both buildable.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader

FROM base AS production

COPY --from=deps /app/vendor /app/vendor
COPY . /app

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /app/storage \
    && chown -R www-data:www-data /app/storage

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV WAASEYAA_DB=/app/storage/waaseyaa.sqlite

EXPOSE 9000

USER www-data
