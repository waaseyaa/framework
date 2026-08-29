# Skeleton production Docker image: `intl`, `zip`, and the Alpine build deps

Applies to any application created from the Waaseyaa skeleton before this
change that builds a container image from the shipped `Dockerfile`. If you have
never run `docker build` against that file, you have not seen the failure —
because it never produced an image at all.

## What was wrong

`skeleton/Dockerfile`'s `base` stage read:

```dockerfile
RUN apk add --no-cache \
    sqlite-libs \
    icu-libs \
    && docker-php-ext-install \
    intl \
    opcache \
    pdo_sqlite
```

Three independent defects, in the order a build hits them:

1. **`intl` had no headers and no toolchain.** `docker-php-ext-install` shells
   out to `phpize`/`configure`, which needs the ICU *development* package.
   `icu-libs` ships only the shared objects, so the build stopped at:

   ```
   checking for icu-uc >= 57.1 icu-io icu-i18n... no
   configure: error: Package requirements (icu-uc >= 57.1 icu-io icu-i18n) were not met:

   Package 'icu-uc' not found
   Package 'icu-io' not found
   Package 'icu-i18n' not found
   ```

2. **`opcache` cannot be installed on this base image at all.**
   `php:8.5-fpm-alpine` already links Zend OPcache (and `pdo_sqlite`)
   statically into the binary. A static extension produces no shared module, so
   `docker-php-ext-install opcache` fails its install step:

   ```
   Installing shared extensions:     /usr/local/lib/php/extensions/no-debug-non-zts-20250925/
   cp: can't stat 'modules/*': No such file or directory
   make: *** [Makefile:89: install-modules] Error 1
   ```

   This was masked by defect 1 — the build never reached it.

3. **`zip` was missing entirely.** `waaseyaa/structured-import` requires
   `ext-zip` at runtime, so once the base stage was repaired the `deps` stage
   refused the lock file:

   ```
   waaseyaa/structured-import v0.1.0-alpha.299 requires ext-zip * ->
   it is missing from your system. Install or enable PHP's zip extension.
   ```

## What changed

The `base` stage now installs runtime libraries as explicit packages, adds the
headers and toolchain under a virtual package, compiles only the extensions
that are actually missing, and removes the toolchain **inside the same `RUN`**:

```dockerfile
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
```

Two details are load-bearing and easy to get wrong when copying this:

- **The delete must share the `RUN` with the install.** An `apk del` in a
  separate `RUN` leaves every build-only byte committed in the earlier layer.
  The final filesystem then looks clean to `docker run` and to `apk info`,
  while `docker save` still carries `gcc`, `make`, `autoconf`,
  `/usr/include/unicode/`, `zip.h` and the ICU/libzip `.pc` files in a layer
  anyone who can pull the image can read.
- **Install the runtime libraries explicitly, before the virtual package.**
  `icu-dev` and `libzip-dev` pull `icu-libs` and `libzip` as dependencies. If
  they only arrive that way, `apk del .build-deps` takes them with it and the
  image ships an `intl.so`/`zip.so` that cannot resolve its libraries.

The trailing `php -m` assertions run inside the image being built. They exist
because defect 2 is a property of the *base image*, not of this Dockerfile: if
a future `php:8.5-fpm-alpine` stops bundling OPcache or `pdo_sqlite`, the build
fails loudly instead of shipping a silently degraded runtime.

## Upgrade an existing project

Replace the `RUN` block in your project's `Dockerfile` with the one above. If
you have added your own extensions, put their `-dev` packages in `.build-deps`
and their runtime libraries in the first `apk add`, and keep the `apk del` in
the same `RUN`.

Confirm the result against the image, not the file:

```sh
docker build --target production -t my-app:check .
docker run --rm my-app:check php -m
```

`intl`, `zip`, `pdo_sqlite` and `Zend OPcache` must all appear.

To confirm no build dependency survived — which `docker run` cannot tell you —
read the saved layers:

```sh
docker save my-app:check -o /tmp/image.tar
mkdir -p /tmp/image && tar -xf /tmp/image.tar -C /tmp/image
find /tmp/image -type f \( -name layer.tar -o -path '*/blobs/*' \) \
  -exec tar -tf {} \; 2>/dev/null \
  | grep -E '^(usr/bin/(gcc|cc|make|autoconf)|usr/include/unicode/|usr/lib/pkgconfig/icu-uc\.pc)($|/)'
```

That must print nothing. If it prints anything, your `apk del` is in a later
`RUN` than the `apk add` it undoes.

## Serving topology

The image still declares `EXPOSE 9000` on `php:8.5-fpm-alpine`, i.e. it serves
FastCGI and expects a web server in front of it, while the documented
development story is FrankenPHP. That mismatch is deliberately left alone here;
this change makes the existing topology build, it does not choose a different
one.

## Regression proof

`ci/skeleton-create-project` builds the **production** stage from the real
`composer create-project` consumer it already creates, then reads three things
out of the resulting image: that `intl`, `zip`, `pdo_sqlite` and `Zend OPcache`
load under `php -m`, that the generated `.env` is absent while `.env.example`
survives, and that no build-only path appears in any blob of `docker save`.
`bin/check-skeleton-docker-secret-exclusion` (#2647) continues to bound the
build context independently.
