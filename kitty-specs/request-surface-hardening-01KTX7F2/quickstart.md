# Quickstart: Verifying Request-Surface Hardening

**Mission**: request-surface-hardening-01KTX7F2

Reviewer's hands-on script — each step maps to an acceptance scenario in [spec.md](spec.md).

## 1. Anonymous discovery reveals no type ids (scenario 1, SC-001)

```bash
./vendor/bin/phpunit packages/api/tests/Unit/ApiDiscoveryControllerTest.php --no-progress
./vendor/bin/phpunit tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php --no-progress
```

By hand against a dev server: `curl -s http://localhost:8080/api` unauthenticated → `meta` + `links.self` only, **zero** entity-type links (grep the body for any registered type id — none appears). Authenticated (session cookie) → all discoverable types listed. A type registered with `discoverable: false` appears for nobody, admin included, while its `/api/<type>` CRUD endpoints keep enforcing access normally.

## 2. Denied single read is byte-identical to missing (scenario 2, SC-002, NFR-002)

```bash
./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerDeniedNotFoundTest.php --no-progress
```

By hand: pick a real id in a gated type and a nonexistent id; fetch both anonymously and `diff` the two response bodies — identical bytes (status `404`, title `Not Found`, same detail string, no `code` member). A PATCH on a viewable-but-not-updatable entity still returns a genuine `403 FORBIDDEN` (FR-004).

## 3. Bearer hardening (scenario 3, SC-003)

```bash
./vendor/bin/phpunit packages/mcp/tests/Unit/Auth/ --no-progress
```

By hand: block the service account behind a configured token (`status = 0` / `setActive(false)` + save), then POST any JSON-RPC body to `/mcp` with that bearer token → 401 `Unauthorized`, indistinguishable from a wrong token. Re-activate → authenticates again. The existing 7-test `BearerTokenAuthTest` matrix passes unchanged.

## 4. Relative WAASEYAA_DB resolves against the project root (scenario 4, SC-004)

```bash
./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php --no-progress
./vendor/bin/phpunit tests/Integration/DbPath/ --no-progress
```

By hand: `WAASEYAA_DB=./storage/waaseyaa.sqlite`, start the dev server with a docroot CWD (`cd public && php -S localhost:8080 index.php`), hit any endpoint, then from the project root run `bin/waaseyaa about` — both report/use `{projectRoot}/storage/waaseyaa.sqlite`; `ls public/storage` shows nothing. Point `WAASEYAA_DB` *into* `public/` deliberately → one boot-time `warning` naming the resolved path and the docroot; boot still succeeds (FR-008).

## 5. Unchanged behavior pins (scenario 5)

Absolute `WAASEYAA_DB` values and the unset default resolve byte-identically to before (unit matrix in `DatabaseBootstrapperTest`); `../`-climbing relatives resolve relative to the project root (edge case); `db:init --dry-run` reports the same path the kernel opens; `health:report` / `about` display the resolved path.

## 6. Gates

```bash
composer verify          # suite + phpstan + composer policy + dead-code + getQuery gate
bin/check-package-layers # no new manifest edges anywhere in this mission
```

CHANGELOG check: `[Unreleased]` (C-001 — not a pre-stamped alpha.206 heading) leads with the consumer-visible 403→404 change on denied singles, then the authenticated-only discovery default + `discoverable` flag, the bearer constant-time + blocked-account hardening, and the path-resolution fix + docroot warning. Spec docs updated in the same PR: `api-layer.md`, `mcp-endpoint.md`, `infrastructure.md`.
