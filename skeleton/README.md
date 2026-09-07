# Waaseyaa Application

A Waaseyaa CMS application.

[**Discord**](https://discord.gg/ZzQNhrBb7U) | [GitHub](https://github.com/waaseyaa/framework) | [Website](https://waaseyaa.org)

## New project

```bash
composer create-project waaseyaa/waaseyaa my-app --stability=dev
cd my-app
php vendor/bin/waaseyaa site:init      # 2. site contract
php vendor/bin/waaseyaa install:init   # 3. schema + configuration
composer site-verify                   # 4. verification
composer run dev                       # 5. serve
```

These five phases are the whole fresh-project lifecycle, and they are ordered.

### What each phase does

| Phase | Command | What it produces | What breaks if you skip it |
|---|---|---|---|
| 1. Create | `composer create-project` | The application tree, `.env`, and installed dependencies. | — |
| 2. Site contract | `site:init` | `.waaseyaa/site.yaml`, the governed artifact set, and `bin/maintenance/site-verify`. | Verification has nothing to verify: `composer site-verify` exits 3 and tells you to run this. |
| 3. Install | `install:init` | The migration ledger, the entity storage schema, and the active configuration generation. | The site has no active configuration generation, so runtime entity writes fail and the site cannot boot outside explicit development mode — even though verification may pass. |
| 4. Verify | `composer site-verify` | A strict site-contract diagnosis plus the generated acceptance tests. Read-only: it never opens or creates the database. | — |
| 5. Serve | `composer run dev` | FrankenPHP on `http://127.0.0.1:8080`. | — |

`install:init` is the single materialization step; it subsumes `migrate` and
`schema:sync` and is the only command that activates the configuration
generation. It is idempotent, so re-run it after any later `site:init`.

`composer site-verify` is the portable entry point. Its materially equivalent
Linux and native Windows behavior is exercised by Framework CI; this is not a
macOS evidence claim. The `.ci/site-verify` shell adapter is a POSIX-only
convenience that calls the same implementation.

The full native Linux/Windows entrypoint boundary, including generated
maintenance commands, test launchers, local AI, and MCP launchers, is in the
[native host support contract](https://github.com/waaseyaa/framework/blob/main/docs/specs/native-host-support.md).

Use `./vendor/bin/waaseyaa` for the CLI. Optional path-linked `waaseyaa/*` checkouts: copy `composer.local.json.example` to `composer.local.json` (see [docs/local-dev.md](docs/local-dev.md)).

`site:init` records the site's product decisions and generates supported
content, subscription, and governed-authoring integrations. The page builder
is one shared revisioned service used by both the generic Admin SPA and an
enabled Anokii shell. High-volume Updates, Events, Jobs, and Announcements use
their faster typed content forms and can be placed on pages through governed
listing blocks.

`composer site-verify` is the local, provider-neutral verification boundary. The
included GitHub workflow merely calls it; another hosted or local runner can do
the same without changing the application contract.

Before customizing the application, read [Application anatomy and ownership](docs/application-anatomy.md).
It maps common framework concepts to their supported application extension
points and makes the security boundary explicit.

## Directory Structure

```
bin/
├── post-create-setup.php  One-time setup after `create-project`
└── maintenance/         Audit/release helpers (optional for beginners)

.ci/
├── site-verify.php      Portable verification entry (`composer site-verify`)
└── site-verify          POSIX shell adapter for the same entry

src/
├── Http/           Front-controller support (BootFailureResponder)
└── Provider/       Service providers (bootstrapping, DI, routing)
```

The skeleton ships only what a fresh application needs to boot (#2438): no
empty placeholder directories. `src/Access/`, `src/Controller/`, `src/Domain/`,
`src/Entity/`, `src/Ingestion/`, `src/Search/`, `src/Seed/`, `src/Support/`,
`tests/Integration/`, and `migrations/` are not scaffolded up front — each
appears the moment something writes a real file into it, whether that is a
generator (`make:content-type` creates `src/Entity/` and `src/Provider/`
together; `make:migration` creates `migrations/`) or your own first file in
that role. See [Application anatomy](docs/application-anatomy.md) for the full
ownership map and the path each area is created on.

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
composer run dev                    # Serve on FrankenPHP at http://127.0.0.1:8080
./vendor/bin/phpunit                # Run tests
./vendor/bin/waaseyaa optimize:manifest  # Rebuild provider manifest
./vendor/bin/waaseyaa serve              # Single-worker php -S dev server (zero-config; not for the admin SPA's SSE or production)
./vendor/bin/waaseyaa                    # CLI
./vendor/bin/waaseyaa site:init          # Initialize/regenerate the governed site contract
./vendor/bin/waaseyaa install:init       # Apply migrations, sync entity schema, activate the configuration generation (idempotent)
composer site-verify                     # Offline provider-neutral site verification (portable; use this on Windows)
.ci/site-verify                          # POSIX shell adapter for the same verification
./bin/maintenance/waaseyaa-audit-site    # Optional convergence preflight (POSIX only)
```

### Required PHP extensions

This app defaults to a **SQLite** database (`storage/waaseyaa.sqlite`), so the PHP
runtime must have **`pdo_sqlite`** and **`sqlite3`** (and `sodium`). These are
declared in `composer.json`, so `composer install` flags a runtime missing them.

The S1 production topology is one application node and one authoritative local
SQLite file. File-backed connections verify WAL, foreign keys, and a bounded
5000 ms busy timeout. Do not configure a database DSN, UNC/network share,
replica, or `:memory:` production database. A separate search database, when
configured, is only a non-authoritative rebuildable projection and obeys the
same local SQLite connection contract.

### Serving with FrankenPHP (`composer run dev`)

`composer run dev` runs the app on [FrankenPHP](https://frankenphp.dev) — the real
concurrent runtime — in classic per-request mode, bound to loopback on a
non-privileged port (no privileged-port or HTTPS-certificate prompt):

```bash
composer run dev   # → http://127.0.0.1:8080  (Ctrl+C to stop)
```

**The first run downloads the FrankenPHP binary for you** (via the optional
`waaseyaa/frankenphp` package, installed by default in the skeleton) — so there
is nothing to download or place by hand. If the binary isn't present yet,
`composer run dev` offers to fetch it; you can also do it up front:

```bash
php vendor/bin/waaseyaa frankenphp:install
```

The implementation targets **Windows, macOS, and Linux with zero PATH setup**,
but the Framework's current native Windows CI does not exercise FrankenPHP
serving; see the
[native host support contract](https://github.com/waaseyaa/framework/blob/main/docs/specs/native-host-support.md)
for the evidence boundary. `composer run dev` routes to the `waaseyaa dev`
command via Composer's own PHP (`@php`), which resolves the `frankenphp` binary
to an **absolute path** and execs it directly — you never add the FrankenPHP
directory to `PATH`.

> **Do NOT put the FrankenPHP directory on `PATH`.** The official Windows release
> is a full PHP SDK that bundles its own `php.exe` with OpenSSL disabled — on
> `PATH` it shadows your system PHP and breaks Composer (TLS to Packagist fails).
> `waaseyaa dev` sidesteps this entirely: it execs `frankenphp` by absolute path
> and never invokes the bundled `php.exe`.

**Binary resolution order:** `FRANKENPHP_BIN` (an absolute path) → the managed
install from `frankenphp:install` (under `vendor/bin/`) → a known install location
(`%USERPROFILE%\.frankenphp\frankenphp.exe` on Windows; `/usr/local/bin`,
`/usr/bin`, `/opt/homebrew/bin`, `~/.frankenphp` on macOS/Linux) → `frankenphp` on
`PATH`. If none resolve, `composer run dev` prints exactly what to do (and offers
to install). Override the listen address with `WAASEYAA_DEV_LISTEN`, or point at a
custom binary:

```bash
# POSIX
FRANKENPHP_BIN=/opt/frankenphp/frankenphp composer run dev
# Windows (PowerShell)
$env:FRANKENPHP_BIN="C:\tools\frankenphp\frankenphp.exe"; composer run dev
```

Classic mode uses FrankenPHP's built-in SQLite — **no `php.ini` hack needed**.

`./vendor/bin/waaseyaa serve` remains the zero-dependency `php -S` dev server (no
FrankenPHP required); it is fine for quick edits but is **not** the right runtime
for the admin SPA's live `/api/broadcast` SSE connection or for production.

**Worker mode (advanced).** For the warm, worker-mode runtime (best for heavy
SSE), run FrankenPHP natively against the committed `config/frankenphp/`:

```bash
PHP_INI_SCAN_DIR="$PWD/config/frankenphp" frankenphp run --config config/frankenphp/Caddyfile
```

The committed Caddy worker block sets `WAASEYAA_FRANKENPHP_WORKER=1` only in
the worker process. The front controller never infers worker mode from
`frankenphp_handle_request()` existing, because classic FrankenPHP exposes that
function too. Classic `php-server`, PHP-FPM, and `php -S` therefore always use
the single-request path.

Use `PHP_INI_SCAN_DIR` (additive), **never** `PHPRC` — `PHPRC` *replaces* the
runtime's bundled `php.ini`, which on shared-extension builds (e.g. the official
Windows release) strands `pdo_sqlite`/`sqlite3` and 500s every request with
`could not find driver`. The committed `php.ini` does not enable those extensions
itself (mainstream builds already provide them); uncomment its `extension=` lines
only for a custom build that genuinely lacks SQLite.

### Upgrading the framework

This skeleton requires `waaseyaa/framework` with a **caret** constraint
(`^0.1.0-alpha.NNN`), so a plain `composer update waaseyaa/framework` takes the
next point release. Keep it a caret:

```bash
composer update waaseyaa/framework   # moves to the latest matching alpha
```

> Avoid `composer require waaseyaa/framework:0.1.0-alpha.NNN` — an **exact**
> version writes a pinned constraint, and then `composer update` silently does
> nothing on later releases. Use `composer require waaseyaa/framework:^0.1.0-alpha.NNN`
> (with the caret) if you ever re-add it.

## First 60 Seconds

```bash
composer install
composer run dev
```

`composer run dev` serves the whole app on FrankenPHP — including the prebuilt
admin SPA at `/admin` (served from `public/`; no separate build step). Open
`http://127.0.0.1:8080`.

## Optional: Admin SPA hot-reload (HMR)

The admin SPA ships as a prebuilt bundle, so most apps need nothing extra. If you
are developing a custom Nuxt admin, run its dev server in a second terminal
alongside `composer dev`:

```bash
# Terminal 1 — the app on FrankenPHP
composer dev
# Terminal 2 — the admin SPA dev server (HMR), pointed at the backend
NUXT_BACKEND_URL=http://127.0.0.1:8080 vendor/bin/waaseyaa admin:dev
```

Set `WAASEYAA_ADMIN_PATH` (or `extra.waaseyaa.admin_path` in `composer.json`) to a
Nuxt admin package outside this skeleton if `admin:dev` cannot find one.

## Configuration

- `config/waaseyaa.php` — Framework configuration
- `config/entity-types.php` — Custom entity types
- `config/services.php` — Service overrides
- [Production web-server examples](https://github.com/waaseyaa/framework/blob/main/docs/deployment-web-servers.md) — Apache, nginx, and Caddy front-controller configuration

## License

GPL-2.0-or-later
