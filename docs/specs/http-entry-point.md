# HTTP entry point (`public/index.php`)

## Contract

Waaseyaa applications use a **single canonical** front controller at `public/index.php`, identical across sites (same model as Laravel or Drupal core’s `index.php`).

The file MUST:

1. Declare strict types.
2. Require the project’s Composer autoloader: `require __DIR__ . '/../vendor/autoload.php';`
3. Construct `Waaseyaa\Foundation\Kernel\HttpKernel` with `dirname(__DIR__)`.
4. Call `$kernel->handle();`

No session `ini_set`, no custom routing, no outer `try/catch` around `handle()`. Boot failures, routing errors, middleware failures, and uncaught dispatch exceptions are handled inside `HttpKernel`.

The Waaseyaa **monorepo** root uses the same file at `public/index.php` after `composer install` in the repository root (standard `vendor/autoload.php` layout).

## Web-server handoff contract

Production web servers must use `public/` as the document root and forward every
request that is not a real file to `public/index.php`. The application skeleton
ships an Apache `public/.htaccess` rewrite for hosts that permit `AllowOverride`;
explicit Apache `FallbackResource`, nginx `try_files`, and Caddy examples live in
[`docs/deployment-web-servers.md`](../deployment-web-servers.md). The skeleton's
known clean-URL sentinel route is probed by `health:check` when
`diagnostics.clean_url_probe_url` is configured, so a DirectoryIndex-only setup
cannot report healthy.

## Source of truth

The authoritative bytes live in the Waaseyaa skeleton:

- [`skeleton/public/index.php`](../../skeleton/public/index.php)
- [`skeleton/bin/golden-public-index.php`](../../skeleton/bin/golden-public-index.php) — same content, used by [`skeleton/bin/waaseyaa-audit-site`](../../skeleton/bin/waaseyaa-audit-site) for mechanical verification.

After changing the skeleton entry file, update `golden-public-index.php` in the same commit.

## Session cookie hardening

Per-site session cookie options (e.g. `httponly`, `secure`, `samesite`) belong in `config/waaseyaa.php` under `session.cookie`, applied by `SessionMiddleware` before `session_start()`. See [`middleware-pipeline.md`](./middleware-pipeline.md).

## Exceptions

Apps with a **documented** non-standard entry (legacy bespoke front controllers) may set `WAASEYAA_AUDIT_SKIP_PUBLIC_INDEX=1` when running `./bin/waaseyaa-audit-site` until migrated. Record remediation in the site’s convergence audit (Section 8 of [`per-site-convergence-audit.md`](./per-site-convergence-audit.md)).

## Symfony decoupling (mission 1107)

Per ratified contract C-002 of mission 1107-api-symfony-decoupling, app code can type-hint `Waaseyaa\Foundation\Http\Request` instead of `\Symfony\Component\HttpFoundation\Request`. The Waaseyaa name is a `class_alias` of Symfony's class, registered via `autoload.files` in `packages/foundation/composer.json`, so all Symfony methods remain callable; the alias is loaded at composer bootstrap regardless of optimization mode.

The mission's charter is **request/response/event-dispatch only** (Path R-narrow). Routing internals stay Symfony-coupled — `RouteBuilder` consumers continue to import `Symfony\Component\Routing\Route` directly. A future routing-decoupling mission is filed as a separate follow-up.

## Implementation gotchas

- **Response flow is return-based**: `HttpKernel::handle()` and `ControllerDispatcher::dispatch()` return Symfony `Response` objects. `public/index.php` calls `$response->send()`. No `exit` in framework internals.
- **Dev-mode SAPI guard**: Use `PHP_SAPI === 'cli-server'` to gate dev-only behavior (e.g., `DevAdminAccount` in `index.php`). Classes with constructor guards must also allow `cli` SAPI for PHPUnit to instantiate them.
- **Dev fallback account requires three conditions**: `HttpKernel::shouldUseDevFallbackAccount()` needs ALL of: (1) `PHP_SAPI === 'cli-server'`, (2) `isDevelopmentMode()` (`APP_ENV=local`), (3) `config['auth']['dev_fallback_account'] === true` (via `WAASEYAA_DEV_FALLBACK_ACCOUNT=true` in `.env`). Missing any one silently disables the dev admin — the SPA shows a login page instead of auto-authenticating. The skeleton's `.env.example` sets this to `true` by default.
- **CORS origins configurable**: `HttpKernel::handleCors()` reads `cors_origins` from `config/waaseyaa.php`. Defaults to `localhost:3000` and `127.0.0.1:3000`. Mismatched origins are logged. If Nuxt dev server binds to a non-standard port, add it to the config array.
