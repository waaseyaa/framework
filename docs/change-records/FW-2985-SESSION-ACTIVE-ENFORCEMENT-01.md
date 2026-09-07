# FW-2985-SESSION-ACTIVE-ENFORCEMENT-01 — Unconditional active-account admission in SessionMiddleware

Status: implemented (repair candidate)

Issue: #2985 (F9 finding)

## Problem

`SessionMiddleware` enforced account-active status only when an optional
`AuthenticationEligibilityInterface` from `waaseyaa/auth` was wired.
Metapackage consumers on `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`
without `waaseyaa/auth` could admit a persisted disabled `User` as
authenticated on both the existing-session and generic bearer resolution paths.
With the policy wired, the same request was refused.

Independent audit evidence: `docs/audits/2985-f9/` (read-only checkout).

## Decision

Make active-account enforcement unconditional in `SessionMiddleware` for both
resolution paths:

1. **Existing PHP session** — after generation comparison, refuse when
   `UserInternalFieldReaderInterface::verification($user)->active` is false;
   clear session identity and log by user id only.
2. **Pre-resolved bearer `User`** — refuse before the optional eligibility
   gate; replace `_account` and `AccountContext` with `AnonymousUser`.

Leave verified-email enforcement in the optional
`AuthenticationEligibilityInterface` unchanged. Fail closed when a `User` reaches
either branch without an internal reader (constructor remains nullable for
legacy callers; production `HttpKernel` always wires the audited reader).

MCP durable bearer behaviour is out of scope; it already re-queries
`status = 1` independently.

## Security impact

**Route-level authentication admission only.** A disabled account no longer
resolves as authenticated when optional `waaseyaa/auth` eligibility is absent.
This repair does **not** demonstrate protected-data exposure; downstream entity
policies and authorization gates remain outside scope (F1, F2, F3, F7).

## Verification

Focused unit coverage in `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
exercises both paths across:

| Case | Policy | Expected |
|---|---|---|
| inactive | not wired | refused |
| active | not wired | accepted |
| active, unverified | wired, require verified | refused |
| active, verified | wired | accepted |
| any `User`, missing reader | n/a | refused (fail closed) |

Session cases use matching `session_generation` so revocation does not mask
the active check.
