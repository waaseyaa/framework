# Specification Quality Checklist: OIDC Flows Completion

**Feature**: [spec.md](../spec.md)

## Content Quality
- [x] Mission scoped to a single coherent goal (finish the OIDC issuer surface end-to-end) — no scope creep into consumer-side oauth-provider work or admin-distinct concerns.
- [x] "Why this mission exists" grounded in concrete prior decisions: alpha-to-beta-plan item that finishing OIDC unblocks Tsen'awt; gap-matrix row A2; scaffold-only state per inventory.
- [x] Cross-package constraint (L1 oidc + L4 route lift via `AuthOidcRouteServiceProvider`) explicitly called out and the existing `AuthOidcRouteServiceProvider` pattern is mandated.
- [x] DIR-004 binding (field-access governs userinfo claims) is the spec's central security thesis, surfaced in FR-005, NFR-004, the Risks section, and the WP03 plan.
- [x] In-scope and out-of-scope explicitly enumerated; introspection, dynamic registration, JWE, hybrid/implicit flows, end-session, and multi-tenant partitioning all listed as out-of-band.
- [x] Risks listed with mitigations: userinfo bypass, PKCE downgrade, refresh-token replay, signing-key leakage, discovery hard-coding, oidc → api layer creep.
- [x] Decisions pre-resolved: oidc owns its own token storage; JSON:API (not GraphQL) for admin CRUD; Nuxt (not Inertia) for the SPA; consent is per-(account, client, scope-set); RP-initiated logout deferred.
- [x] Decisions deferred to implementer: JWT library, encryption-at-rest for private keys, "revoke all tokens" admin affordance — each with the decision-preference order from the charter.

## Requirement Completeness
- [x] FR/NFR/C separated, IDs unique (FR-001..FR-011, NFR-001..NFR-004, C-001..C-005).
- [x] Acceptance criteria present and gate-mapped (phpunit, cs-check, phpstan, package-layers, dead-code, getquery-bindings, composer-policy + admin SPA gates).
- [x] Edge cases: expired JWT, revoked access token, malformed bearer header, unknown revoke target (still 200), reused auth code, refresh-token replay (chain cascade), missing PKCE on public client, `code_challenge_method=plain` (rejected), unsupported response_type, scope-expansion across consent.
- [x] FR-011 names the integration test as the regression anchor for downstream consumer-app SSO work.
- [x] NFR-004 explicitly demands the userinfo controller binds the field-access account to the token subject, not the request `_account`; reviewer focus reinforces this with a manual `rg` step.

## Filing Readiness
- [x] Mission scaffold materialized in `kitty-specs/oidc-flows-completion-01KSEFTP/`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01..WP05-*.md`, `checklists/requirements.md` all populated.
- [x] Five WPs with explicit dependencies (WP01 first; WP02 + WP04 depend on WP01; WP03 depends on WP01+WP04; WP05 depends on WP01+WP04).
- [x] Each WP file has YAML frontmatter matching `tasks/README.md` format and references the spec's FR/NFR/C IDs in `requirement_refs`.
- [x] Implementer can act on each WP without coming back for questions: every owned file is named, every controller pattern has a pointer to the file to read first, every test asserts a specific behaviour.
- [x] Reviewer focus enumerated in plan.md (userinfo account binding; PKCE S256 mandatory; chain cascade; private-key non-exposure; layer cleanliness; oauth-provider untouched; discovery is config-derived).
