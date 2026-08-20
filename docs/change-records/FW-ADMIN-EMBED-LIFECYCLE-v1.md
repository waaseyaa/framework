# FW-ADMIN-EMBED-LIFECYCLE-v1 — safe host/embed lifecycle

- Parent: `cf4bd663ae5fa96b11683e48fdd487b6214ee192`
- Contract: `docs/specs/admin-spa.md`
- Issue: `#2455`
- Authority: Framework source, generated Admin distribution, tests, and hosted
  issue/pull-request evidence; no integration or publication authority

## Sequence

1. Record the versioned, identity-only same-origin lifecycle contract before
   implementation.
2. Commit retained-red protocol, destination, entity-editor, page-builder, and
   session-expiry tests.
3. Implement one shared emitter and bounded failure classifier, then wire the
   existing canonical workspaces without adding an alternate mutation path.
4. Rebuild the governed Admin distribution from reviewed source and verify the
   exact candidate with focused frontend/PHP tests plus repository preflight.
5. Exercise the exact installed Framework cohort in Sheguiandah acceptance
   before the consumer overlay is allowed to depend on the protocol.

## Contract boundary

The shell-free entity editor and page builder emit a versioned lifecycle
envelope only to `window.parent` at `window.location.origin`. The envelope may
identify the editor surface and resource and may report ready, dirty, saved,
deleted, or a closed failure kind. It never includes content values, validation
detail, policy reasons, credentials, tokens, or server response bodies.

The child owns mutation and validation through the existing canonical
workspaces. A same-origin parent may use lifecycle state only for chrome,
refresh, and close confirmation; it cannot command a save, bypass access, or
inspect iframe DOM. Legacy entity-editor saved/deleted identity notifications
remain during the compatibility interval.

Session expiry, permission refusal, optimistic conflict, network failure, and
server failure are distinct bounded states. Unknown failures collapse to a
generic server failure. A host must validate both `event.origin` and
`event.source`; this child-side contract does not make a parent trustworthy by
origin alone.

## External interlock

Issues, branches, commits, pushes, and a draft pull request are authorized for
this work unit. Merge, tag, release, split-package publication, deployment,
production operations, and repository settings changes are not authorized.

## Evidence disposition

This record remains open until source and generated distribution correspond,
focused and repository gates pass at one exact commit, hosted checks are
reconciled, and the same Framework cohort passes Sheguiandah acceptance.

## Framework candidate checkpoint

- retained-red contract commit: `596f81123`;
- source implementation commit: `50ed16112`;
- governed distribution commit: `fff4fb5af`;
- Admin distribution source signature:
  `88c27482bc22e8e33054dfc2cbe3e5719e0513ecf9eba5827ef82def5eab9682`;
- two clean hermetic builds produced the same complete distribution digest:
  `22d59ca4d9520f310c39a8f1c87c97e2e5096aad66d5ab38273e4a6707456185`.

Verification on PHP 8.5.9 and Node 24.19.0:

- Admin Vitest: 90 files, 622 tests, green;
- Admin typecheck and lint: green (lint retains the repository warning
  baseline, zero errors);
- Admin Surface Unit: 231 tests, 1,049 assertions, green;
- Integration: 2,008 tests, 8,876 assertions, green;
- Architecture: 282 tests, 24,264 assertions, one environment skip, green;
- full preflight: 33 gates, including PHPStan and dead-code, green;
- Unit: 11,721 tests and 231,852 assertions completed with one reproducible
  untouched baseline failure in
  `AnthropicProviderTransportTest::withoutALowSpeedGuardAStalledStreamHoldsTheWorkerForTheWholeTotal`;
  the test expects a three-second total bound but this host's cURL transfer ends
  at the one-second connect bound. No #2455 file is in that package or test.

The Framework-only candidate is therefore ready for hosted review, while this
record remains open for the required exact-cohort Sheguiandah acceptance and
hosted-check reconciliation. No integration or publication action is implied.
