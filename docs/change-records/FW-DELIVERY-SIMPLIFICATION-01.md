# FW-DELIVERY-SIMPLIFICATION-01

Date: 2026-10-17. Requested by Russell: simplify delivery for an agent-agnostic
workflow and retain independent review for high-risk changes.

## Scope and decision

One short delivery skill remains the entrypoint. Optional parallel coordination
joins the existing CI-repair and candidate-consumer references. Routine delivery
uses one agent; no vendor or machine-local orchestration service is required.
The Framework agent contract owns risk-based review. Both repositories' testing
guidance follows that policy. Dedicated audit verification retains its separate
independent roles; accepted remediation uses the delivery risk policy.

Ordinary docs/presentation/narrow changes allow recorded self-review. High-risk
authentication, authorization, persistence/migration, execution, isolation,
significant shared architecture and mandatory safeguards still require an
independent reviewer. File extensions do not decide risk. Existing hooks,
release gates and publication authorization are unchanged.

The skill no longer duplicates architecture, test-runner and publication policy.
Native-host and preflight distinctions move to the existing local testing policy.
Policy prose is checked through scenario review rather than exact-string
snapshots; installer provenance/refusal tests and skill packaging validation
remain. Dedicated audit assertions remain scoped to the audit method.

## Ownership and verification

The task owner edits only the delivery skill/references, maintainer README,
Framework AGENTS/agent contract/testing policy/workflow spec, convergence
remediation cross-references, MaintainerSkillsTest and this record/fragment.
Studio companion ownership is its private-MVP workflow, local testing policy
and matching record. Bases: Framework
`38bd59f386de7234de12c2a6171c6b6f2692cc69`; Studio
`8b88ab4bc2a4de85ecea8cda51a72d27f8e01eaf`. Both candidates use isolated
`codex/delivery-simplification` branches; existing lanes are preserved.

Focused plan: candidate-local dependency install, maintainer-skill validation,
MaintainerSkillsTest, PHP syntax/style for that file, changed document links and
Git whitespace. No broad runtime suite is justified. Mandatory hooks still run.
This batch changes routine workflow and preserves high-risk requirements; its
review is recorded self-review, not an independent verdict.

Scenario review must cover: a wording-only edit; a narrow UI fix; an authorization
change; a migration; a policy edit weakening an isolation gate; an explicit
independent-audit requirement; an authorized parallel handoff; and unpublished
Framework consumer development. Validate scope, review method, evidence and
publication boundaries without executing those scenarios against live services.

Framework publication currently depends on the separate support-review repair
tracked by #3206. Do not alter its policy dates or bypass hooks in this batch.

## Verification results

- Candidate-local Composer install completed from the unchanged lock. No donor
  vendor tree or autoload override was used.
- `php vendor/bin/phpunit tests/Architecture/MaintainerSkillsTest.php --no-coverage`:
  25 tests, 316 assertions passed, PHP 8.5.5, 40.569 seconds, 22 MB.
- After the final policy wording clarification, the four affected repository
  skill tests passed again: 4 tests, 23 assertions, 0.853 seconds, 22 MB.
  Installer behavior/tests were unchanged, so their first run remains applicable.
- Repository skill validation passed for delivery's four files and convergence's
  17 files. Skill Creator's `quick_validate.py` passed for delivery. PHP syntax,
  changelog-fragment validation, 44 existing local Markdown link targets across
  both candidates and Git whitespace checks passed.
- `php bin/check-support-contract` still reports the expired review deadline.
  The full pre-push qualification is not claimed; #3206 remains its repair owner.

The following is the task owner's manual scenario review, not independent
agent testing or runtime evidence:

| Scenario | Resulting instruction |
| --- | --- |
| Wording-only documentation edit | Recorded self-review and document checks; no mandatory second agent. |
| Narrow visual presentation fix | Focused UI checks and self-review unless it affects a high-risk behavior. |
| Authorization behavior change | Independent review before integration, with affected refusal evidence. |
| Data migration | Independent review and persistence/migration evidence. |
| Policy edit weakening an isolation gate | High risk despite Markdown format; independent review remains required. |
| Explicit independent-audit criterion | Owning audit verification still applies; delivery does not waive it. |
| Authorized parallel work | Load only the coordination reference; preserve scoped custody and handoffs. |
| Unreleased Framework consumer development | Use exact candidate integration; keep published release qualification separate. |

The core is approximately 620 words, down from 2,464. Existing release and
permission gates are unchanged. No runtime implementation, release or deployment
is part of this work.
