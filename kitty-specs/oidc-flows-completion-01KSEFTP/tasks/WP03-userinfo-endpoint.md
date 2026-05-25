---
work_package_id: "WP03"
title: "Userinfo endpoint (field-access bound, DIR-004)"
dependencies: ["WP01", "WP04"]
requirement_refs:
  - "FR-005"
  - "NFR-001"
  - "NFR-002"
  - "NFR-004"
  - "C-001"
  - "C-002"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. During implement, this WP may branch from a dependency-specific base (WP01 + WP04), but completed changes must merge back into main unless redirected explicitly."
subtasks:
  - "T007"
  - "T008"
phase: "Phase 3 - Claims surface"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/oidc/src/Userinfo"
execution_mode: "code_change"
owned_files:
  - "packages/oidc/src/Userinfo/UserinfoController.php"
  - "packages/oidc/src/Userinfo/UserinfoClaimResolver.php"
  - "packages/oidc/tests/Unit/Userinfo/UserinfoControllerTest.php"
  - "packages/oidc/tests/Unit/Userinfo/UserinfoClaimResolverTest.php"
  - "packages/routing/src/AuthOidcRouteServiceProvider.php"
  - "docs/specs/access-control.md"
history: []
---

# WP03 — Userinfo endpoint (field-access bound, DIR-004)

**Mission:** `oidc-flows-completion-01KSEFTP`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP03`>
```

## The DIR-004 binding (read before writing anything)

Userinfo is **the** highest-visibility OCAP surface in the framework. Every consumer app SSO claim — `email`, `phone_number`, profile data — flows through this endpoint. DIR-004 requires the OCAP-by-architecture commitment to apply here as much as it does to admin entity serialisers.

The wrong account binding silently leaks claims. **The account passed into the field-access check MUST be the token-bound account (the user being looked up), not the IdP service account or the request's `_account` attribute.** Reviewer will check exactly this.

Read:
- `packages/access/src/AccessPolicyInterface.php` + `FieldAccessPolicyInterface.php` — the contract you're calling.
- `packages/api/src/Serializer/ResourceSerializer.php` — how the rest of the framework binds field-access for entity reads. Mirror the binding direction (subject = the data; account = the requester).
- `packages/user/src/User.php` — the entity you're projecting into claims.
- `docs/specs/access-control.md` + `docs/specs/field-access.md` — DIR-004 is the binding rule.
- WP04's `KeyMaterialProviderInterface` + JWKS — you call `allActive()` to validate the bearer JWT.

## Subtasks

**T007 — UserinfoClaimResolver + controller**

- `src/Userinfo/UserinfoClaimResolver.php` — `@api`. Pure mapping:
  - `openid` → `['sub']`
  - `profile` → `['name', 'preferred_username', 'updated_at']`
  - `email` → `['email', 'email_verified']`
  - `address` → `['address']`
  - `phone` → `['phone_number', 'phone_number_verified']`
  - `claimsFor(array $grantedScopes): array<string>` returns the deduped union.
  - `scopeDescriptionFor(string $scope): string` returns a human-readable label for the consent screen (used by WP05).

- `src/Userinfo/UserinfoController.php` — `__invoke(Request): JsonResponse`:
  1. Parse `Authorization: Bearer <jwt>`. If absent or malformed → `401` with `WWW-Authenticate: Bearer error="invalid_token"` and `{error: "invalid_token"}` body.
  2. Resolve all active JWKS keys via `KeyMaterialProviderInterface::allActive()`. Verify the JWT against the key whose `kid` matches the JWT header. Verify `iss` matches `OidcIssuerConfig::issuerUrl`, `exp` not passed. Failure → same 401 shape.
  3. Look up `oidc_access_token` by `jti`. If missing or `revoked_at IS NOT NULL` → 401.
  4. Load the `User` entity for `account_id`. Build the requesting `AccountInterface` for the token subject (i.e. construct from the loaded user). **DO NOT** read `_account` from the request — that's the IdP service caller (NFR-004).
  5. Read `scope` from the JWT. Resolve `UserinfoClaimResolver::claimsFor(scopes)`.
  6. For each candidate claim:
     - Map claim → `User` field name (e.g., `preferred_username` → `name` if your `User` doesn't have a separate username column; document the mapping in code comments).
     - Locate the `FieldAccessPolicyInterface` for the `User` entity (same lookup `EntityAccessHandler` uses — `instanceof` against the registered access policy).
     - Call `fieldAccess($user, $fieldName, $tokenSubjectAccount)`. If Forbidden → **omit the claim entirely** (do not emit `null`, do not emit `""`, do not emit the key with any value).
     - Otherwise → include `claim => $user->get($fieldName)`.
  7. `sub` is always included (the JWT subject identifier). Even if `profile` field-access denies everything, `openid` ensures `sub` is returned.
  8. Return `JsonResponse($claims)` with `Content-Type: application/json` (NOT `application/vnd.api+json`).

**T008 — Tests + spec stamp**

- `tests/Unit/Userinfo/UserinfoClaimResolverTest.php` — scope subset returns subset; `openid` alone → `['sub']`; full set returns deduped union; unknown scope is ignored.
- `tests/Unit/Userinfo/UserinfoControllerTest.php` — use an anonymous `FieldAccessPolicyInterface` that allows everything except `email` for the test user. Assert:
  - happy path `openid email profile`: response contains `sub`, `name`, `preferred_username`, `updated_at`, `email_verified` — but **NOT** the `email` key at all (`assertArrayNotHasKey('email', $body)`).
  - expired JWT → 401 with `WWW-Authenticate: Bearer error="invalid_token"`.
  - revoked access token → 401.
  - missing Authorization header → 401.
  - malformed JWT → 401.
  - `Content-Type` is `application/json`, not `application/vnd.api+json`.

- `docs/specs/access-control.md` — add a new section `## OIDC userinfo + field-access (DIR-004 binding)` describing:
  - The userinfo endpoint runs every claim through the field-access policy.
  - The account passed to the policy is the token subject, not the IdP service account.
  - Forbidden claims are omitted entirely from the response (not nulled).
  - This is the OCAP-by-architecture binding for the SSO surface — bypassing it requires a charter amendment per DIR-004.
  Stamp the file with `<!-- Spec reviewed YYYY-MM-DD - oidc-flows-completion-01KSEFTP - WP03 - userinfo field-access binding -->`.

**Routes:**
- `packages/routing/src/AuthOidcRouteServiceProvider.php` — add `oidc.userinfo` → `GET|POST /oidc/userinfo`, controller `'Waaseyaa\\Oidc\\Userinfo\\UserinfoController::__invoke'`, `_public: true` (bearer auth inside controller).

## Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/oidc/tests/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. **NFR-004 manual check:** `rg -n '_account|getAttribute' packages/oidc/src/Userinfo/UserinfoController.php` — confirm there is no read of `_account` from the request. The account constructed for the field-access call must come from the loaded `User`.
6. Confirm the omitted-claim test uses `assertArrayNotHasKey`, not `assertNull` or `assertEmpty`.

## Commit + handoff
- Commits (footer `Mission: oidc-flows-completion-01KSEFTP`):
  - `feat(oidc): userinfo endpoint (field-access bound per DIR-004)`
  - `feat(routing): /oidc/userinfo route`
  - `docs(access-control): userinfo + field-access binding section (DIR-004)`
- Then:
  ```
  spec-kitty agent tasks mark-status T007 T008 --status done --mission oidc-flows-completion-01KSEFTP
  spec-kitty agent tasks move-task WP03 --to for_review --mission oidc-flows-completion-01KSEFTP --note "Userinfo bound to field-access per DIR-004; token subject is the requesting account (NFR-004 verified)."
  ```

## Report back with
1. Commit SHAs.
2. The exact controller line that builds the `AccountInterface` passed to `fieldAccess()`. Reviewer will read this against NFR-004.
3. Output of the `omits-email-when-policy-denies` test — paste the assertion line and the asserted response body.
4. Confirm `Content-Type: application/json` (not JSON:API envelope) — paste the assertion.

## Activity Log
- 2026-05-25T05:30:07Z – unknown – subagent shipped retroactively
- 2026-05-25T05:30:17Z – unknown – code already committed: 221ac1248..a7240b47f
- 2026-05-25T05:30:27Z – unknown – Opus review: all 5 OIDC WPs cleanly committed; gates pass; DIR-004 userinfo field-access wiring confirmed; subagent self-corrected DatabaseInterface::getConnection() per CLAUDE.md gotcha
