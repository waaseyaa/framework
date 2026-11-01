# FW-STARTER-RECONCILIATION-01

November 1, 2026. Russell authorized reconciling old CLI/AI work, deleting
obsolete branches/worktrees, and landing the updated starter plan on main.
Integration base: `c4aff57585a8a7bd13e27e77d710f10dcc0cf662`.
Native Git: `C:/Program Files/Git/cmd/git.exe`.

## Decisions

- Land FW-BASIC-APP-STARTER-01 as the current planning entry. Shared setup,
  default AI in both starters, CMS-free basic dependencies and BAS-14 audit
  readiness are retained. Package ownership and manifests remain slice-1
  design outputs, not shipped behavior or authorization to implement all slices.
- Carry the reviewed CLI IO repair from `bcd9f910a616d5b2b6c7516e9087388bd9400864`
  without changing its runtime/test delta. Main's three affected PHP files were
  byte-identical to the repair's parent. The previous independent read-only
  review and installed-consumer limits remain in FW-3117-CLI-IO-01.
- Retain the three October 11 AI package reports and ledgers from `19c77027b`.
  Preserve original audit identities and partial status. Package manifests have
  only first-party version-floor changes since then; current dependency and
  starter closure assessment is still required. Do not promote historical
  source evidence to current installed qualification.
- Consolidate the old AI program and CLI convergence record into current
  ownership. Superseded landscape/dispatch/forge drafts stay in Git history;
  do not import old ADR edits or a second active starter roadmap.
- Retire the redundant October reconciliation stack after its useful deltas
  are integrated. Its publishing repairs already have main ancestry or
  patch-equivalent integrations; the old combined candidate is not merged.

## Verification and review plan

The integrator owns one candidate-local locked Composer installation and
focused CLI, Bimaaji collision, PhaseN installer and audit-ledger validation.
The old regression must fail on current main before the repair. Inspect the
installed Symfony Console lifecycle to confirm exception catching and auto-exit
remain disabled and Waaseyaa's outer exit mapping remains in control.
Run normal hooks and preflight. Observe main-feedback after normal fast-forward
integration. This is not release, published-package or complete starter proof.

Historical independent review applies to the unchanged repair source/test
delta. This reconciliation's document consistency and evidence equivalence
review are integrator self-review, not a new independent audit. If source,
exit policy or the repair tests change beyond that reviewed delta, require a
new independent review before landing that change.

## Current results

- Current-main negative control: both real argv noninteraction cases fail,
  proving the defect remains. Restored repair: 14 CLI tests / 45 assertions pass.
- Human-owned Bimaaji collision: one test / two assertions passes, exit 1 and
  exact human bytes preserved. This does not prove all-target transactionality.
- PhaseN Bimaaji install: 22 tests / 113 assertions pass on native Windows.
- Package audit ledger validation: 130 tests / 226 assertions pass.
- Symfony Console 8.0.13 source confirms `run()` configures IO and restores
  verbosity state, while disabled catch/auto-exit preserve the outer wrapper.
- Evidence logs and exact cleanup inventory are held outside disposable
  worktrees at `C:/dev/waaseyaa/starter-reconciliation-evidence/20261101`.
  Hosted Linux and final integration receipts are recorded there after landing.

## Cleanup boundary

Remove only verified merged or explicitly superseded work. Preserve dirty
social-planning files in the primary checkout and any unreconciled audit probes.
Check current status, ancestry, process/lease references and ignored evidence
before each removal; preserve only necessary evidence with source hashes.
Use exact resolved native Windows paths, no forced main update and no blanket
deletion of remote branches with unexplained unique work. Record removal
failures and residual directories honestly. No replacement archive branches.

Release, publication and deployment holds remain. #3117, #3118 and broader
AI delivery are not closed by this bounded integration.
