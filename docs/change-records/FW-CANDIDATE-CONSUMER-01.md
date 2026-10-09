# FW-CANDIDATE-CONSUMER-01: unreleased consumer integration

Date: 8 October 2026. Base: `0b8e53fa27deb112effb9cb960e304d021223157`.

## Scope and decision

Update the repository-owned delivery skill with a conditional reference for
application integration before Framework publication. Candidate qualification
can unblock development; published adoption remains a distinct release gate.
The reference owns the reproducible archive, Composer overlay, package closure
and evidence procedure. Application schedules and product policy remain outside
Framework. No runtime changes, installer, package release or deployment.

## Acceptance and verification

- Entry point routes to one consumer-neutral reference.
- Exact candidate pairs, installed-package custody, coherent dependency closure
  and no-dev checks are required; monorepo success is insufficient.
- Published adoption and deployment remain separate, authorized steps.
- `php bin/maintainer-skills validate` passed for both maintained skills.
- `git diff --check` passed. Independent immutable-delta review is recorded in
  the delivery handoff. No consumer overlay was executed for this docs-only work.
- Runtime suites are unchanged and were not rerun. Hosted qualification and
  landing remain separate from this local documentation update.
