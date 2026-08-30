FROM php:8.5-fpm-alpine AS base

# One RUN, on purpose.
#
# `php:8.5-fpm-alpine` already carries `Zend OPcache` and `pdo_sqlite`
# statically linked into the binary — `docker-php-ext-install opcache` there is
# not a no-op, it is a hard build failure (`cp: can't stat 'modules/*'`,
# because a static extension produces no shared module to install). `intl` and
# `zip` are the extensions this image genuinely has to compile: `zip` is a
# runtime requirement of `waaseyaa/structured-import`, so without it the
# `deps` stage's `composer install --no-dev` refuses the lock file.
#
# Compiling them needs a C toolchain ($PHPIZE_DEPS, exported by the official
# PHP images) and the ICU / libzip *headers*. `icu-libs` carries only the
# shared objects, so configure aborted with `Package requirements (icu-uc >=
# 57.1 icu-io icu-i18n) were not met` and the image never built at all (#2673).
#
# The headers and the toolchain are installed under the `.build-deps` virtual
# package and deleted inside the SAME layer. Splitting the delete into its own
# RUN would still leave every build-only byte committed in the earlier layer,
# so the image would ship them even though the final filesystem looked clean.
#
# `icu-libs`, `libzip` and `sqlite-libs` are installed first as explicit
# (non-virtual) packages, which is what keeps `apk del .build-deps` from taking
# the runtime shared objects with it when it removes `icu-dev`/`libzip-dev`.
#
# The closing `php -m` assertions run inside the image being built: they are
# what turns a future base image that stops bundling OPcache or pdo_sqlite
# into a loud build failure instead of a silently degraded runtime.
RUN apk add --no-cache \
        icu-libs \
        libzip \
        sqlite-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install intl zip \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/pear \
    && php -m | grep -qx 'intl' \
    && php -m | grep -qx 'zip' \
    && php -m | grep -qx 'pdo_sqlite' \
    && php -m | grep -qx 'Zend OPcache' \
    && php -r 'exit((new Collator("en_US"))->compare("a", "b") === -1 ? 0 : 1);'

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
