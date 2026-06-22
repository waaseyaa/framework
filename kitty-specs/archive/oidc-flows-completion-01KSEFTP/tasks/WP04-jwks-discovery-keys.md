---
work_package_id: "WP04"
title: "JWKS + discovery + signing-key storage + rotation CLI"
dependencies: ["WP01"]
requirement_refs:
  - "FR-006"
  - "FR-007"
  - "FR-008"
  - "NFR-003"
  - "C-001"
  - "C-002"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. During implement, this WP may branch from a dependency-specific base (WP01), but completed changes must merge back into main unless redirected explicitly."
subtasks:
  - "T009"
  - "T010"
  - "T011"
  - "T012"
phase: "Phase 2 - Crypto + metadata"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/oidc/src/Key"
execution_mode: "code_change"
owned_files:
  - "packages/oidc/migrations/2026_05_25_000003_oidc_signing_key_schema.php"
  - "packages/oidc/src/Key/SigningKeyRepository.php"
  - "packages/oidc/src/Key/RealKeyMaterialProvider.php"
  - "packages/oidc/src/Jwks/JwksDocumentBuilder.php"
  - "packages/oidc/src/Jwks/JwksController.php"
  - "packages/oidc/src/Discovery/DiscoveryDocumentBuilder.php"
  - "packages/oidc/src/Discovery/DiscoveryController.php"
  - "packages/cli/src/Command/Oidc/RotateSigningKeyCommand.php"
  - "packages/oidc/tests/Unit/Key/SigningKeyRepositoryTest.php"
  - "packages/oidc/tests/Unit/Jwks/JwksDocumentBuilderTest.php"
  - "packages/oidc/tests/Unit/Discovery/DiscoveryDocumentBuilderTest.php"
  - "packages/cli/tests/Unit/Command/Oidc/RotateSigningKeyCommandTest.php"
  - "packages/routing/src/AuthOidcRouteServiceProvider.php"
history: []
---

# WP04 — JWKS + discovery + signing-key storage + rotation CLI

**Mission:** `oidc-flows-completion-01KSEFTP`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP04`>
```

## Read first

- WP01's `KeyMaterialProviderInterface` + `InMemoryKeyMaterialProvider` — you are replacing the in-memory placeholder with a DB-backed implementation.
- `packages/cli/src/Command/` — pick a recent simple admin command (e.g. an `optimize:*` or `schema:*` command) to mirror constructor + `configure()` style.
- `packages/oidc/migrations/2026_04_26_000001_oidc_client_schema.php` — migration shape.
- OIDC Discovery 1.0 (`https://openid.net/specs/openid-connect-discovery-1_0.html`) and JWKS (RFC 7517) for the exact field list.

## Subtasks

**T009 — Signing-key storage + repository**

- `migrations/2026_05_25_000003_oidc_signing_key_schema.php` — table `oidc_signing_key` with `kid` UUID PK, `algorithm` (enum, `RS256` only), `private_key_pem` TEXT, `public_key_pem` TEXT, `created_at` int, `rotated_out_at` int nullable. Add index on `(rotated_out_at)` with NULLS FIRST semantics (or simulate via the query).
- `src/Key/SigningKeyRepository.php` — methods:
  - `currentKey(): SigningKey` — the row with `rotated_out_at IS NULL` ordered by `created_at DESC LIMIT 1`. If none exists, **auto-bootstrap** one (single transaction, idempotent) — this prevents a cold-start chicken-and-egg where the first auth request fails because no key exists.
  - `previousKey(): ?SigningKey` — the most recent `rotated_out_at IS NOT NULL` row.
  - `allActive(): array` — current + previous (used by JWKS + bearer validation).
  - `rotate(): SigningKey` — atomic. Generates a new RS256 keypair via `openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])`. Inserts as current. Marks the prior current as `rotated_out_at = now()`. Deletes any key whose `rotated_out_at` is older than the new previous (keeps only current + previous).
- `src/Key/RealKeyMaterialProvider.php implements KeyMaterialProviderInterface` — bridges repo to issuer.
- Update `OidcServiceProvider::register()`: rebind `KeyMaterialProviderInterface` → `RealKeyMaterialProvider` (replaces WP01's `InMemoryKeyMaterialProvider`).
- `tests/Unit/Key/SigningKeyRepositoryTest.php` — `DBALDatabase::createSqlite()` + the migration. Assert: auto-bootstrap on empty table; rotate creates new current + marks prior current rotated_out; second rotate prunes the oldest key; `allActive()` returns current + previous.

**T010 — JWKS endpoint**

- `src/Jwks/JwksDocumentBuilder.php` — `@api`. Pure function. `build(array $keys): array` returns `{"keys": [{kty, kid, use, alg, n, e}, ...]}`. Extract `n` (modulus) and `e` (exponent) from each public PEM via `openssl_pkey_get_details()['rsa']`. Base64url-encode (no padding). **Public components only — `private_key_pem` MUST NOT appear anywhere in the output (NFR-003).**
- `src/Jwks/JwksController.php` — `__invoke(): JsonResponse` calls `SigningKeyRepository::allActive()`, passes to `JwksDocumentBuilder::build()`, returns with `Cache-Control: public, max-age=86400`. `Content-Type: application/json`.
- `tests/Unit/Jwks/JwksDocumentBuilderTest.php` — given two known keys, assert exact output shape; assert NO occurrence of `private` or PEM markers in the serialised JSON.

**T011 — Discovery endpoint**

- `src/Discovery/DiscoveryDocumentBuilder.php` — `@api`. Pure function. `build(OidcIssuerConfig $config): array` returns:
  ```
  {
    "issuer": <config issuer>,
    "authorization_endpoint": "<issuer>/oidc/authorize",
    "token_endpoint": "<issuer>/oidc/token",
    "userinfo_endpoint": "<issuer>/oidc/userinfo",
    "jwks_uri": "<issuer>/.well-known/jwks.json",
    "revocation_endpoint": "<issuer>/oidc/revoke",
    "end_session_endpoint": "<issuer>/oidc/end_session",  // documented as deferred but listed
    "scopes_supported": ["openid","profile","email","address","phone"],
    "response_types_supported": ["code"],
    "grant_types_supported": ["authorization_code","refresh_token"],
    "subject_types_supported": ["public"],
    "id_token_signing_alg_values_supported": ["RS256"],
    "token_endpoint_auth_methods_supported": ["client_secret_basic","client_secret_post","none"],
    "code_challenge_methods_supported": ["S256"]
  }
  ```
  All URL fields derive from `$config->issuerUrl` — no hard-coded values.
- `src/Discovery/DiscoveryController.php` — `__invoke(): JsonResponse` calls builder, returns with `Cache-Control: public, max-age=3600`.
- `tests/Unit/Discovery/DiscoveryDocumentBuilderTest.php` — for two distinct issuer URLs, assert the output URLs differ accordingly; assert the response_types list contains only `code` (C-004); assert `code_challenge_methods_supported` contains only `S256` (C-003).

**T012 — Rotation CLI command (FR-008)**

- `packages/cli/src/Command/Oidc/RotateSigningKeyCommand.php` — `name = "oidc:rotate-signing-key"`. `@api`. Constructor takes `SigningKeyRepository`. `execute()` calls `rotate()`, prints `New current kid: <kid>` and `Rotated out kid: <previous kid>` (or `"(none — first key)"` if there was no prior).
- `tests/Unit/Command/Oidc/RotateSigningKeyCommandTest.php` — `CommandTester` against a real repo + SQLite. Run twice; assert second run prints both kid lines; assert `currentKey()` returns the new key.

**Routes:**
- `packages/routing/src/AuthOidcRouteServiceProvider.php` — add:
  - `oidc.jwks` → `GET /.well-known/jwks.json`, controller `'Waaseyaa\\Oidc\\Jwks\\JwksController::__invoke'`, `_public: true`.
  - `oidc.discovery` → `GET /.well-known/openid-configuration`, controller `'Waaseyaa\\Oidc\\Discovery\\DiscoveryController::__invoke'`, `_public: true`.

## Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/oidc/tests/ packages/cli/tests/ packages/routing/tests/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. **NFR-003 manual check:** `rg -n 'private_key_pem' packages/oidc/src/Jwks packages/oidc/src/Discovery` returns **nothing**.
6. Visually inspect the JWKS test fixture output — confirm no PEM markers, no `private`, no `d` (RSA private exponent component) anywhere.

## Commit + handoff
- Commits (footer `Mission: oidc-flows-completion-01KSEFTP`):
  - `feat(oidc): signing-key storage + RealKeyMaterialProvider`
  - `feat(oidc): JWKS + discovery endpoints`
  - `feat(cli): oidc:rotate-signing-key command`
  - `feat(routing): /.well-known/jwks.json + /.well-known/openid-configuration routes`
- Then:
  ```
  spec-kitty agent tasks mark-status T009 T010 T011 T012 --status done --mission oidc-flows-completion-01KSEFTP
  spec-kitty agent tasks move-task WP04 --to for_review --mission oidc-flows-completion-01KSEFTP --note "JWKS exposes public components only; discovery is config-derived; rotation CLI keeps current+previous."
  ```

## Report back with
1. Commit SHAs.
2. The exact JWKS output for a freshly-bootstrapped key (paste the JSON).
3. Confirmation that `rg 'private_key_pem' packages/oidc/src/Jwks packages/oidc/src/Discovery` is empty.
4. Confirmation that changing the `OidcIssuerConfig::issuerUrl` value changes every URL in the discovery output (paste the test + assertion).
5. Paste the `oidc:rotate-signing-key` output from a fresh run.

## Activity Log
- 2026-05-25T05:30:09Z – unknown – subagent shipped retroactively
- 2026-05-25T05:30:19Z – unknown – code already committed: 221ac1248..a7240b47f
- 2026-05-25T05:30:30Z – unknown – Opus review: all 5 OIDC WPs cleanly committed; gates pass; DIR-004 userinfo field-access wiring confirmed; subagent self-corrected DatabaseInterface::getConnection() per CLAUDE.md gotcha
