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

Session expiry, permission refusal, optimistic conflict, validation refusal,
network failure, and server failure are distinct bounded states. Unknown
failures collapse to a generic server failure. A non-advisory HTTP 422 is
validation; an advisory-acknowledgement HTTP 428 is not a lifecycle failure. A
host must validate both `event.origin` and `event.source`; this child-side
contract does not make a parent trustworthy by origin alone.

## External interlock

Issues, branches, commits, pushes, and a draft pull request are authorized for
this work unit. Merge, tag, release, split-package publication, deployment,
production operations, and repository settings changes are not authorized.

## Evidence disposition

This record remains open until source and generated distribution correspond,
focused and repository gates pass at one exact commit, hosted checks are
reconciled, and the same Framework cohort passes Sheguiandah acceptance.

## Framework candidate checkpoint

Historical unique commits on `cf4bd663ae5fa96b11683e48fdd487b6214ee192`:

- retained-red contract commit: `596f81123`;
- source implementation commit: `50ed16112`;
- governed distribution commit: `fff4fb5af` (skipped during current-main
  replay; dist was rebuilt once after source resolution);
- evidence commit: `1775cc116`.

Transplant onto `44e9b34c43abc7f854ccf409c937a7f000dab7c2`:

- retained-red contract commit: `7c7364207`;
- source implementation commit: `30b7e5217`;
- evidence commit: `bec4e503d`.

Current-main remediation then classifies non-advisory HTTP 422 as `validation`
and clears stale `useSchema` error/failure before cache or in-flight returns,
while preserving merged #2468 advisory HTTP 428 review.

- Admin distribution source signature:
  `fc324a6c558a2e4211adeb51baac1ec9879a5f6e93a8a4abf5ea9a2644e449a1`;
- two clean hermetic builds produced the same complete distribution digest:
  `a927a9304349894226802023d59dda4fd20a51904f34a129338f733c53c20418`.

Verification on PHP 8.5.8 and Node 24.19.0 after the transplant and
remediation:

- focused embed lifecycle, SchemaForm, save-advisory, and useSchema
  regressions: 48 tests, green;
- Admin Vitest: 93 files, 645 tests, green;
- Admin typecheck: green;
- Admin Surface Unit: 337 tests, 1,427 assertions, green;
- `composer cs-check`: green.

The Framework-only candidate is therefore ready for hosted review after
preflight, while this record remains open for exact-cohort Sheguiandah
acceptance and hosted-check reconciliation. No integration or publication
action is implied.
