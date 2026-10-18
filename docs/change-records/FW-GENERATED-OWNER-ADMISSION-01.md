# FW-GENERATED-OWNER-ADMISSION-01

Date: 2026-10-17. Owner: Codex. Tracking: Framework #3073.
Base: `ef186fdb3e0f60b8694b6ae7e2d1521d4bfea7fc`.
Worktree: `codex/generated-owner-admission-3073`.

## Contract and scope

The generated Community Events owner can authenticate but cannot enter the
default admin surface. Its registered role grants application permissions;
the surface currently requires `administer content`, which also grants broad
content management. Granting that permission would exceed an entry-only repair.

The stock generic host will admit an account holding the explicit, narrow
`access administration` permission or the existing broad `administer content`
permission. These are distinct current capabilities: entry alone does not grant
entity, field, workflow or operational access. An explicitly supplied host
admission permission remains an exact requirement, with no stock alternative.
The Community Events blueprint declares the narrow permission in its generated
catalogue and assigns it only to `event_administrator`. Role names themselves
never establish admission; the reserved `administrator` provisioning refusal
and blueprint prohibition remain unchanged.

Reuse the existing immutable principal, permission catalogue, generated role
provider, provisioning service and admin routes. No new validator, registry,
schema field, dependency or host factory is needed. This is Waaseyaa admission
policy over the existing Symfony HTTP boundary, not a generic mechanism to
replace. No Studio code, stored-account migration, hosting or deployment change.
Existing generated apps/owners do not silently acquire the new grant: they must
adopt reviewed regenerated artifacts and explicitly reassign the updated role.

## Verification and custody

Codex owns the generic host, canonical Community Events starter, affected tests,
admin/generation specs, release fragment and this record. No other lane edits.
First capture the owner login-to-admin failure with generated permissions and
real SQLite persistence. Then prove admission succeeds, a role without the
permission is refused, custom host requirements remain exact, entry alone
cannot read/write content, and the reserved role stays refused.

Run focused host/admission, provisioning and starter/generator consumers;
mandatory preflight/hooks remain enforced. Broaden only for a concrete shared
caller failure. Windows full-suite evidence is not claimed. The integration
test is a real persisted-account/controller/session composition, not a rendered
browser or Studio hosted journey. Independent non-author review is required
before merge because this changes authorization.

## Implementation evidence

- Reproduced on the base with the new admission discriminator: 10 tests,
  13 assertions, one failure because the narrow permission was rejected.
- The fresh-process generated-owner regression independently reproduced login
  200 followed by admin-session 401 (one test, seven assertions, one failure).
- On the repaired implementation, the combined host/provider, provisioning,
  blueprint/starter and admin-surface integration selection passed: 473 tests,
  2,842 assertions in 23.399 seconds on native Windows PHP 8.5.5 / PHPUnit 13.1.14.
- Authentication is explicitly required even when an unauthenticated principal
  claims the entry permission. Its regression failed before the guard. Existing
  authenticated account doubles now declare their authentication state.
- Changed PHP files were formatted with the repository PHP CS Fixer rules;
  `git diff --check` passed. No dependency or production provisioning code changed.

Focused command (candidate-local dependencies installed from composer.lock):

```text
php vendor/bin/phpunit packages/admin-surface/tests/Unit/Host packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderFieldSchemaAuthorityTest.php packages/cli/tests/Unit/UserProvisioning packages/cli/tests/Unit/Handler/UserProvisionRegisteredHandlerTest.php packages/cli/tests/Integration/UserProvisionRegisteredProcessTest.php packages/cli/tests/Unit/Site/Blueprint packages/site-contract/tests/Unit/Starter tests/Integration/AdminSurface --no-coverage
```

Immutable review identity, mandatory gate results and publication evidence are
recorded on the associated PR. This record does not assert those pending gates
have passed. Native Git: `C:/Program Files/Git/cmd/git.exe`.
