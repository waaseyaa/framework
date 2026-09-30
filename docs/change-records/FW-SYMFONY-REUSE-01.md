# FW-SYMFONY-REUSE-01: maintained infrastructure by default

Related audit program: #3118. Implementation owner: this policy lane at
`framework-worktrees/symfony-reuse-policy`; branch
`codex/framework-symfony-reuse-policy`; current base
`755a463ff28c659b0e7f855eaf9b79d94632b362`.

## Decision and scope

The maintainer requires Framework-wide preference for maintained Symfony
components over equivalent home-rolled infrastructure. This includes runtime
packages, build/maintenance tooling and the skills used to audit and deliver
the framework. The policy is not limited to ai-vector or HTTP.

The canonical rule lives in `docs/governance/agent-contract.md`. `CLAUDE.md`
routes agents to it; repository-canonical package-convergence and delivery
skills operationalize it. Installed skill copies remain generated artifacts.

## Behavior

New infrastructure uses suitable maintained components by default. Audits
inventory existing duplication and route verified replacement opportunities
into bounded remediation. Components need not already be installed. Retention
requires an evidenced capability or compatibility gap, owner and review
trigger; migration deferrals require acceptance and a removal condition.
Waaseyaa retains domain policy, sovereignty and lifecycle/public contracts.

This change defines policy and workflow only. It does not select unverified
component versions, replace runtime implementations or authorize unrelated
migrations. Source, failure behavior, public compatibility and installation
profiles must be verified for each actual replacement.

## Validation

Validate canonical skill structure with `php bin/maintainer-skills validate`
and check the patch with `git diff --check`. Review the wording against three
cases: a suitable uninstalled component defaults to reuse; an actual semantic
gap permits an evidenced exception; an unrelated legacy mechanism becomes a
bounded follow-up rather than an unsolicited rewrite.

Historical draft results at base `701dca820`: canonical skill validation passed for both skills, changelog
validation passed for all 22 fragments, and `git diff --check` passed. The
wording covers all three cases above. Default preflight refused before running
any gates because this isolated worktree has no installed `vendor/`; full
qualification is unverified. No runtime code changed.

Landing and installed-copy refresh remain separate from these local edits.

## Current delivery plan

Root owns the seven policy/skill/document files in this isolated lane; other
lanes are preserved. The draft was fast-forwarded onto current main without
conflict or loss of uncommitted work. No runtime, dependency, installer or
consumer application contract changes are included. Generic infrastructure
reuse does not transfer domain ownership or authorize unrelated migrations.

Focused qualification is canonical skill validation, MaintainerSkillsTest,
changelog validation and document/link consistency, followed by default native
Windows preflight and mandatory hooks. Exact-head hosted full qualification
owns the complete verdict. One independent subagent reviews the immutable
candidate for policy consistency, bounded scope, exception accountability and
all three decision cases above. Root owns integration and the serialized local
qualification slot. Broader local testing requires an actual runtime/tooling
change or unexplained focused failure.

Open a separate PR after initial review. Landing requires the same independently
reviewed green SHA, then exact-SHA main feedback. Only after landing, refresh
installed maintainer skills from the clean landed source with the canonical
installer and verify all copies. Draft policy and unrefreshed copies are not
claimed as durably active across future work. No release, tag or deployment.
Current pre-commit native Windows evidence (PHP 8.5.5, candidate-local Composer
install from the current lock): both canonical skills validate; existing
MaintainerSkillsTest passes 25 tests and 339 assertions in 38 seconds; 24
changelog fragments validate; `git diff --check` passes. Default preflight
executed 42 gates in 244.4 seconds with zero failures, zero hosted-required and
four not-applicable. The subsequent documentation wording/evidence delta is
qualified by mandatory hooks and governed material-input reuse. Independent
review and hosted exact-head qualification are pending at this checkpoint.
Lease: explicit retained ownership; the coordinator rejects Windows drive
paths, so its validator is preserved without bypass. Installed copies are
unchanged pending landing.