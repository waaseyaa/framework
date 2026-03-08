# Security Defaults

## Purpose

Defines the security model for Waaseyaa's default content types, API access, and configuration handling. Ensures secrets never leak into version-controlled manifests and that RBAC is enforced at the API layer before entity-level access checks fire.

## Threat Model

The `defaults/` directory contains declarative manifests (YAML) and JSON Schema files. These files are:

- **Version-controlled** — committed to the repository
- **Non-executable** — never `require`d or `eval`d at runtime
- **Read by `DefaultsSchemaRegistry`** — only `*.schema.json` files, only `x-waaseyaa` metadata

Attack surfaces:

| Threat | Mitigation |
|--------|-----------|
| Secret committed to `defaults/` | `bin/check-no-secrets` (CI gate) + `DefaultsSecretsIntegrationTest` (structural) |
| Manifest referencing external endpoint | YAML manifests are governance docs, not runtime config |
| Anonymous write to entity API | Route-level `_authenticated` option on POST/PATCH/DELETE |

## RBAC Enforcement at the API Layer

Access control is enforced in three layers, evaluated in sequence:

| Layer | Mechanism | Failure code |
|-------|-----------|-------------|
| **Route-level authentication** | `_authenticated` route option → `AccessChecker` → `AuthorizationMiddleware` | 401 Unauthorized |
| **Route-level authorization** | `_permission` / `_role` / `_gate` route options → `AccessChecker` | 403 Forbidden |
| **Entity-level access** | `EntityAccessHandler` → `AccessPolicyInterface` implementations | 403 Forbidden |
| **Field-level access** | `EntityAccessHandler::checkFieldAccess()` → `FieldAccessPolicyInterface` | Field omitted from response |

### Route-level authentication

All entity CRUD write routes (POST, PATCH, DELETE) require authentication via the `_authenticated` route option. Anonymous requests receive HTTP 401 with a `WWW-Authenticate: Bearer` header.

Read routes (GET index, GET show) do **not** require authentication at the route level. Entity access policies control read authorization — this preserves the ability for entity types to serve public content.

### Entity-level access for core.note

The `NoteAccessPolicy` enforces:

| Operation | tenant.member | tenant.admin | platform.admin | anonymous |
|-----------|--------------|-------------|---------------|-----------|
| view | Allowed | Allowed | Allowed | Neutral (denied) |
| create | — | Allowed | Allowed | Neutral (denied) |
| update | — | Allowed | Allowed | Neutral (denied) |
| delete | Forbidden | Forbidden | Forbidden | Forbidden |

### Field-level access for core.note

System fields (`id`, `uuid`, `created_at`, `updated_at`) are read-only for all roles except `platform.admin`. `tenant_id` is settable on creation but immutable on update.

See also: `docs/specs/access-control.md`, `docs/specs/field-access.md`.

## Encryption Policy

### Current (pre-v1)

- `body` field is stored as plaintext in the `_data` JSON blob column
- No at-rest encryption is applied by `SqlEntityStorage`
- Transport-layer encryption (TLS) is the operator's responsibility
- The `core.note.yaml` manifest declares `encryption_policy: none`

### Future opt-in path

When field-level encryption is implemented:

- The manifest value will become the algorithm name (e.g., `aes-256-gcm`)
- Key management will be the operator's responsibility via a `WAASEYAA_ENCRYPTION_KEY` env var
- Existing plaintext data will require a migration command

## Secrets Handling

### Invariant: no secrets in manifests

`defaults/*.yaml` and `defaults/*.schema.json` are version-controlled and must **never** contain credentials, tokens, or connection strings. All secrets enter the application exclusively via environment variables.

### Environment variable contract

| Variable | Purpose | Required |
|----------|---------|----------|
| `WAASEYAA_JWT_SECRET` | HS256 shared secret for bearer auth | Only if bearer auth is used |
| `OPENAI_API_KEY` | OpenAI API key for embeddings | Only if `embedding_provider=openai` |
| `WAASEYAA_DEV_FALLBACK_ACCOUNT` | Dev-only auto-auth as platform admin | **Must be false in production** |

Full listing: `.env.example`.

### Configuration secrets

`api_keys` in `config/waaseyaa.php` maps raw API keys to UIDs. This file is **not** version-controlled in deployments (only `skeleton/config/waaseyaa.php` is committed as a template). Deployments must override it via environment-specific config.

### Enforcement

| Check | Type | Location |
|-------|------|----------|
| `bin/check-no-secrets` | Shell grep for token patterns | CI: `security-defaults` job |
| `DefaultsSecretsIntegrationTest` | Structural YAML/JSON value scanning | CI: PHPUnit `--filter Phase22` |

Patterns checked: `sk-*` (OpenAI), `ghp_*` (GitHub), `xox[bp]-*` (Slack), `ya29.*` (Google OAuth), `AIza*` (Google API), PEM private keys, DSN with embedded credentials.

## File Reference

| File | Purpose |
|------|---------|
| `packages/access/src/AccessStatus.php` | `UNAUTHENTICATED` enum case |
| `packages/access/src/AccessResult.php` | `unauthenticated()` factory, `isUnauthenticated()` predicate |
| `packages/access/src/Middleware/AuthorizationMiddleware.php` | 401 response branch |
| `packages/routing/src/AccessChecker.php` | `_authenticated` route option evaluation |
| `packages/routing/src/RouteBuilder.php` | `requireAuthentication()` fluent method |
| `packages/api/src/JsonApiRouteProvider.php` | Authentication on write routes |
| `packages/note/src/NoteAccessPolicy.php` | Entity + field access for core.note |
| `defaults/core.note.yaml` | Governance manifest with encryption_policy |
| `bin/check-no-secrets` | CI shell gate for secret patterns |
| `tests/Integration/Phase22/DefaultsSecretsIntegrationTest.php` | Structural secrets scan |
| `.env.example` | Canonical env var reference |
| `.github/workflows/ci.yml` | `security-defaults` CI job |
