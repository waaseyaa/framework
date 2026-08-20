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
