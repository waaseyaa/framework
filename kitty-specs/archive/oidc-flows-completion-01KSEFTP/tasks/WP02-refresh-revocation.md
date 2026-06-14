---
work_package_id: "WP02"
title: "Refresh + revocation (token endpoint variants)"
dependencies: ["WP01"]
requirement_refs:
  - "FR-003"
  - "FR-004"
  - "NFR-001"
  - "NFR-002"
  - "C-001"
  - "C-002"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. During implement, this WP may branch from a dependency-specific base (WP01), but completed changes must merge back into main unless redirected explicitly."
subtasks:
  - "T005"
  - "T006"
phase: "Phase 2 - Token lifecycle"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/oidc/src/Token"
execution_mode: "code_change"
owned_files:
  - "packages/oidc/src/Token/RefreshTokenGrantHandler.php"
  - "packages/oidc/src/Token/TokenController.php"
  - "packages/oidc/src/Revoke/RevocationController.php"
  - "packages/oidc/tests/Unit/Token/RefreshTokenGrantHandlerTest.php"
  - "packages/oidc/tests/Unit/Revoke/RevocationControllerTest.php"
  - "packages/routing/src/AuthOidcRouteServiceProvider.php"
history: []
---

# WP02 — Refresh + revocation (token endpoint variants)

**Mission:** `oidc-flows-completion-01KSEFTP`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP02`>
```

## Pattern to mirror

- READ WP01's `TokenController` first — `__invoke` is already dispatching on `grant_type`; you are extending it.
- READ `AccessTokenIssuer` + `IdTokenIssuer` — you call these on refresh to issue the rotated pair.
- READ RFC 7009 §2.2 (revocation, including the "200 even for unknown tokens" rule).

## Subtasks

**T005 — Refresh grant + theft-detection chain cascade (FR-003)**

- `src/Token/RefreshTokenGrantHandler.php` — new. `handle(clientId, refreshToken): array`:
  1. Look up `oidc_refresh_token` by jti decoded from the opaque token (or by the token value itself if storing opaque). Validate `client_id` matches; mismatched → `invalid_grant`.
  2. **If `revoked_at IS NOT NULL`:** trigger theft response. `UPDATE oidc_refresh_token SET revoked_at = now() WHERE chain_root_jti = ? AND revoked_at IS NULL`. Cascade: `UPDATE oidc_access_token SET revoked_at = now() WHERE jti IN (SELECT access_token_jti FROM oidc_refresh_token WHERE chain_root_jti = ?)`. Return `invalid_grant`. Log a security event via `LoggerInterface::warning("refresh_token replay detected", ['chain_root_jti' => ..., 'client_id' => ...])`.
  3. **Otherwise:** mark the current pair `revoked_at = now()`. Issue a new pair via `AccessTokenIssuer` / `IdTokenIssuer`. The new refresh row inherits `chain_root_jti` from the current one (preserving the chain). The new id_token preserves the original `auth_time` from the current refresh row.
  4. Return the OAuth-shape JSON.

- `src/Token/TokenController.php` — extend the `__invoke` dispatch to call `RefreshTokenGrantHandler` for `grant_type=refresh_token`. Reject any unknown `grant_type` with `unsupported_grant_type`.

- `tests/Unit/Token/RefreshTokenGrantHandlerTest.php`:
  - happy rotate: old pair revoked, new pair issued, `auth_time` preserved, new `chain_root_jti` matches old.
  - replay: revoked refresh re-used → `invalid_grant` AND the new refresh issued in the prior rotate is now also `revoked_at != null` AND the access tokens for the whole chain are revoked.
  - foreign client: client_id mismatch → `invalid_grant`.

**T006 — Revocation endpoint (FR-004)**

- `src/Revoke/RevocationController.php` — `__invoke(Request): JsonResponse`:
  1. Authenticate client (Basic / POST body / `none` for public). Bad auth → 401 with `{error: "invalid_client"}`.
  2. Read `token` (required) + `token_type_hint` (optional, `access_token` or `refresh_token`).
  3. Look up the token. If hint says refresh, try refresh table first, fall back to access. If hint says access, mirror. If no hint, try refresh then access.
  4. On match: `UPDATE ... SET revoked_at = now()`. If the match was an access token, also revoke its paired refresh (`UPDATE oidc_refresh_token SET revoked_at = now() WHERE access_token_jti = ?`).
  5. On no match: **still return 200** with empty body (RFC 7009 §2.2 — prevents enumeration).
  6. Return 200 with empty body in all success/no-match cases. `Content-Type: application/json`.

- `tests/Unit/Revoke/RevocationControllerTest.php`:
  - known access token: revoked, paired refresh also revoked, 200 empty body.
  - known refresh token: revoked, 200 empty body, paired access NOT revoked (asymmetric per spec — only access→refresh cascade, not refresh→access).
  - unknown token: 200 empty body, no rows changed.
  - bad client auth: 401 `{error: "invalid_client"}`.

**Routes:**
- `packages/routing/src/AuthOidcRouteServiceProvider.php` — add `oidc.revoke` → `POST /oidc/revoke`, controller `'Waaseyaa\\Oidc\\Revoke\\RevocationController::__invoke'`, `_public: true`.

## Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/oidc/tests/ packages/routing/tests/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. Confirm the replay-cascade test asserts the **new** refresh (issued by the legitimate rotate) is revoked, not just the originally-revoked one.

## Commit + handoff
- Commits (footer `Mission: oidc-flows-completion-01KSEFTP`):
  - `feat(oidc): refresh-token grant + theft-detection chain cascade`
  - `feat(oidc): RFC 7009 revocation endpoint`
  - `feat(routing): /oidc/revoke route`
- Then:
  ```
  spec-kitty agent tasks mark-status T005 T006 --status done --mission oidc-flows-completion-01KSEFTP
  spec-kitty agent tasks move-task WP02 --to for_review --mission oidc-flows-completion-01KSEFTP --note "Refresh rotation + revocation + theft-detection chain cascade verified."
  ```

## Report back with
1. Commit SHAs.
2. Paste the exact log line emitted when a refresh-token replay is detected.
3. Confirmation that the replay-cascade test asserts the post-rotate refresh is revoked (paste the assertion).
4. Confirmation that revoking an unknown token returns 200 with empty body (paste the test).

## Activity Log
- 2026-05-25T05:30:07Z – unknown – subagent shipped retroactively
- 2026-05-25T05:30:15Z – unknown – code already committed: 221ac1248..a7240b47f
- 2026-05-25T05:30:25Z – unknown – Opus review: all 5 OIDC WPs cleanly committed; gates pass; DIR-004 userinfo field-access wiring confirmed; subagent self-corrected DatabaseInterface::getConnection() per CLAUDE.md gotcha
