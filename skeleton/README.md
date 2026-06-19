# Waaseyaa Application

A Waaseyaa CMS application.

[**Discord**](https://discord.gg/ZzQNhrBb7U) | [GitHub](https://github.com/waaseyaa/framework) | [Website](https://waaseyaa.org)

## New project

```bash
composer create-project waaseyaa/waaseyaa my-app --stability=dev
cd my-app
```

Use `./vendor/bin/waaseyaa` for the CLI. Optional path-linked `waaseyaa/*` checkouts: copy `composer.local.json.example` to `composer.local.json` (see [docs/local-dev.md](docs/local-dev.md)).

## Directory Structure

```
bin/
├── dev.sh               Local development runner (`composer run dev`)
├── post-create-setup.php  One-time setup after `create-project`
└── maintenance/         Audit/release helpers (optional for beginners)

src/
├── Access/        Authorization policies
├── Controller/    HTTP controllers (thin orchestration)
├── Domain/        Domain logic grouped by bounded context
├── Entity/        ORM entities (pure data models)
├── Ingestion/     Inbound data pipelines (files, email, APIs)
├── Provider/      Service providers (bootstrapping, DI, routing)
├── Search/        Search providers, autocomplete, indexing
├── Seed/          Seeders for dev/local bootstrap
└── Support/       Cross-cutting utilities (ValueObjects, helpers)
```

### Domain Rules

Bounded contexts go under `Domain/<ContextName>/` with optional subdirectories:
`Service/`, `ValueObject/`, `Workflow/`, `Assembler/`, `Ranker/`, `Mapper/`.

### Support Rules

Cross-cutting utilities (validators, slug generators, normalizers, distance
calculators) belong in `Support/`.

### Namespace Rules

Namespaces must match PSR-4 directory structure. Update namespaces in files
and all references when moving code.

## Commands

```bash
composer install                    # Install dependencies
composer run dev                    # Start backend (+ admin HMR when configured)
./vendor/bin/phpunit                # Run tests
./vendor/bin/waaseyaa optimize:manifest  # Rebuild provider manifest
./vendor/bin/waaseyaa serve              # Single-worker php -S dev server (zero-config; not for the admin SPA's SSE or production)
./vendor/bin/waaseyaa                    # CLI
./bin/maintenance/waaseyaa-audit-site    # Optional convergence preflight
```

### Required PHP extensions

This app defaults to a **SQLite** database (`storage/waaseyaa.sqlite`), so the PHP
runtime must have **`pdo_sqlite`** and **`sqlite3`** (and `sodium`). These are
declared in `composer.json`, so `composer install` flags a runtime missing them.

### Serving with FrankenPHP (concurrent runtime)

The quickest way to run the real concurrent runtime is the bundled Composer
script — classic per-request mode against `public/`, bound to loopback on a
non-privileged port so it never triggers a privileged-port or HTTPS-certificate
prompt:

```bash
composer serve:franken   # → http://127.0.0.1:8080  (Ctrl+C to stop)
```

This assumes the `frankenphp` binary is on your `PATH` — install it from
<https://frankenphp.dev> (no per-machine paths to configure). For the warm,
worker-mode runtime (best for the admin SPA's SSE), use the native invocation
shown below instead.

`./vendor/bin/waaseyaa serve` is the plain single-worker `php -S` dev server. It
is a zero-config convenience and is **not** the right runtime for the admin SPA's
live `/api/broadcast` SSE connection or for production.

For a concurrent runtime, run [FrankenPHP](https://frankenphp.dev) **natively** —
the framework does not wrap it in a subcommand (the Symfony/Laravel/Drupal
convention). Install the `frankenphp` binary, then from the project root:

```bash
# Worker mode (recommended) — boots public/index.php once and serves requests
# concurrently across threads, so the admin SPA's SSE stream never starves others:
PHPRC="$PWD/config/frankenphp" frankenphp run --config config/frankenphp/Caddyfile

# Zero-config classic alternative (still concurrent, no Caddyfile):
PHPRC="$PWD/config/frankenphp" frankenphp php-server --root public
```

The `Caddyfile` (worker mode → `public/index.php`) and `php.ini` are committed
under `config/frankenphp/` in this skeleton. `PHPRC` points the embedded PHP at
that `php.ini`, which enables `pdo_sqlite`/`sqlite3` so a stock SQLite app boots
with no hand-edited ini. Most static FrankenPHP builds bundle those extensions,
in which case `PHPRC` is optional.

## First 60 Seconds

```bash
composer install
composer run dev
```

`composer run dev` always starts the PHP app. If an admin Nuxt package is configured,
it also starts the admin dev server with hot reloading.

Open your app at `http://127.0.0.1:8080` (or your configured `APP_HOST` / `APP_PORT`).

## Optional: Admin HMR Setup

If your project has a Nuxt admin app outside this skeleton, point Waaseyaa to it:

```bash
export WAASEYAA_ADMIN_PATH=../waaseyaa/packages/admin
composer run dev
```

When `WAASEYAA_ADMIN_PATH` resolves to a directory containing `package.json`,
the dev command launches both backend and admin HMR together.

## Configuration

- `config/waaseyaa.php` — Framework configuration
- `config/entity-types.php` — Custom entity types
- `config/services.php` — Service overrides

## License

GPL-2.0-or-later
