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

## Source of truth

The authoritative bytes live in the Waaseyaa skeleton:

- [`skeleton/public/index.php`](../../skeleton/public/index.php)
- [`skeleton/bin/golden-public-index.php`](../../skeleton/bin/golden-public-index.php) — same content, used by [`skeleton/bin/waaseyaa-audit-site`](../../skeleton/bin/waaseyaa-audit-site) for mechanical verification.

After changing the skeleton entry file, update `golden-public-index.php` in the same commit.

## Session cookie hardening

Per-site session cookie options (e.g. `httponly`, `secure`, `samesite`) belong in `config/waaseyaa.php` under `session.cookie`, applied by `SessionMiddleware` before `session_start()`. See [`middleware-pipeline.md`](./middleware-pipeline.md).

## Exceptions

Apps with a **documented** non-standard entry (legacy bespoke front controllers) may set `WAASEYAA_AUDIT_SKIP_PUBLIC_INDEX=1` when running `./bin/waaseyaa-audit-site` until migrated. Record remediation in the site’s convergence audit (Section 8 of [`per-site-convergence-audit.md`](./per-site-convergence-audit.md)).
