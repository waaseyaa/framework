# Signup protection

Status: DRAFT, planning authorized 26 October 2026. Portable owner:
[FW-SIGNUP-PROTECTION-01](../change-records/FW-SIGNUP-PROTECTION-01.md).
Execution mirror: [#3234](https://github.com/waaseyaa/framework/issues/3234).
This proposal changes no live authentication contract or default.

## Problem and existing boundaries

Public registration needs reusable anti-automation admission without consumers
copying authentication internals. Source inspection at
`eb46cfa978689673048789814d3e7547594ac174` found registration IP throttling and
the supported allow/deny/approval extension, but no dedicated challenge
disposition or typed challenge evidence in that extension. This is a missing
capability, not a reproduced rate-limit defect. No new runtime tests have run.

[Access control](access-control.md) owns existing authentication, CSRF, session,
verification and rate-limit guarantees. [Auth consumer extensions](auth-consumer-extensions.md)
owns supported policy composition. Email verification already exists; whether
to require it is a consumer policy, not a missing Framework feature.

## Proposed first slice

- One signup admission boundary runs before user persistence, auth-token
  creation, auth mail or authenticated session issuance. API registration and
  application-rendered forms use the same enabled policy. Trusted non-public
  account provisioning remains separately authorized, never implicitly exposed
  as a public escape path.
- Define bounded evidence and allow/challenge/refuse outcomes. Do not give a
  policy unrestricted request bodies, credentials or raw session material.
  A browser helper may supply signed short-lived form proof and a honeypot;
  the API contract must state how it supplies required proof so missing browser
  fields cannot silently bypass the policy.
- Fast submissions, unusual names and browser identifiers are weak signals.
  They must not independently establish bot identity, block legitimate autofill
  or cause permanent account sanctions. A form proof establishes its own narrow
  validity, not that its holder is human.
- An optional maintained challenge integration validates responses server-side
  with expected action/origin, expiry and replay controls. Provider selection,
  default/opt-in behavior and accessible recovery remain design decisions.
  Required checks cannot silently pass during timeout or provider failure;
  recovery must be truthful and bounded, without an unbounded external call
  inside a database transaction. Never retain passwords to resume a challenge.
- Reuse existing durable limits and qualify IP/session scopes, trusted-proxy
  resolution, concurrent admission and rollback accounting. Do not introduce a
  second limiter or call an untested concurrency hypothesis a confirmed defect.
- Reuse bounded audit/event facilities for time, request correlation, policy
  version, reason code and outcome. Account correlation becomes available only
  after successful creation. Define retention, authorized access and keyed
  pseudonymous correlation before implementation. Exclude passwords, proof
  tokens, cookies, full form payloads and private messages from diagnostics.

Evaluate suitable maintained Symfony components and challenge adapters before
adding generic mechanisms. Preserve Framework domain policy in the smallest
owning integration. This does not select a provider or require every visitor to
complete a challenge.

## Acceptance to qualify after implementation authorization

1. Normal desktop/mobile, keyboard, assistive-technology and password-manager
   signup works without unnecessary friction; shared-network users are negative
   controls for overbroad address rules.
2. A deterministic synthetic suspicious submission reaches the configured
   challenge. Valid proof permits the intended action; missing, forged, expired,
   wrong-action/origin or reused proof cannot create an account, send auth mail,
   mint an auth token or authenticate a session.
3. Direct API calls and rendered forms cannot bypass enabled protection. Partial
   wiring fails with an actionable diagnostic, not an accidentally open path.
4. Concurrent proof spending, duplicate submissions, cookie resets, proxy
   boundaries and transaction failure preserve intended attempt accounting and
   prevent duplicate logical registration or orphaned authenticated sessions.
5. Outage and expiry offer a useful accessible retry journey with bounded work.
   Existing anti-enumeration, CSRF, rotation, verification eligibility and access
   controls remain load-bearing.
6. Decision evidence supports investigation without claiming a reliable human/
   bot verdict. Retention and redaction checks cover all refusal/failure paths.
7. A fresh installed consumer, production no-dev composition and exact-version
   package adoption exercise the real boundary. Monorepo passes alone are not
   evidence of published availability or consumer readiness.

## Scope and unresolved decisions

First resolve the smallest supported contract, default/opt-in composition,
challenge provider and failure behavior, non-JavaScript/API recovery, and
retention. Product thresholds, copy and verification policy stay downstream.
Security-sensitive implementation requires independent review.

No login-wide rollout, scraping controls, general risk platform, invasive device
fingerprinting, automatic bans/deletions, retroactive account classification or
production change is included. Existing restrictions and legitimate automation
must be considered explicitly in the supported public registration contract.
