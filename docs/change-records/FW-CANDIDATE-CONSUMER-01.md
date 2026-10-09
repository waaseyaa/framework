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

## Bounded verification repair, 8 October 2026

Owner and final local qualifier: this delivery lane. Independent reviewer:
`/root/review_verification_repair`. The original documentation review is reused:
`/root/review_candidate_guidance`, review chat
`01a11e2e-a647-7811-849f-73aaa853c28c`, approved exact documentation candidate
`4210d923b7eecc9e174cd094495e4b1e4b6c7d97` without findings.
Its parent and live main remain `0b8e53fa27deb112effb9cb960e304d021223157`.

The candidate adds a third delivery-skill file but left the test expecting two.
Main already contains fifteen convergence-skill files while the same test
expects fourteen. The omitted convergence reference was added by
`f099100ceedb5160a9ccc78b6ee2e365a096ecae` (the #3118 reconciliation batch,
Framework maintainer ownership). Both exact reviewed-file rosters also omitted
their corresponding references. Repair only the two explicit counts and add
`candidate-consumer-integration.md` and `repository-reconciliation.md` to the
explicit roster. Exact equality, skill validation, content, provenance and
refusal checks remain intact; counts are not derived from the current files.

Test plan: reproduce the count failure, run the complete affected test file,
then the default governed preflight. Broaden only for an unexplained failure or
a source/configuration/base change affecting additional boundaries. No runtime,
dependency, package public-surface or generated-governance input changed.

### Commands and results

Native Windows PowerShell; PHP 8.5.5, PHPUnit 13.1.14, Composer 2.9.5;
Git executable `C:/Program Files/Git/cmd/git.exe`, Git for Windows Bash
`C:/Program Files/Git/bin/bash.exe`. This is local command evidence, not a
certification of the reference-host tuple in `native-host-support.md`, whose
Composer target is 2.10. No ordinary docs/test landing rule requires a toolchain
upgrade; no hosted/native-host certification or full qualification is claimed.

- `git status --short --branch`, `git rev-parse HEAD`, and
  `git ls-remote origin refs/heads/main`: clean starting candidate and unchanged
  live main verified. Other worktrees were preserved.
- `composer install --no-interaction --no-progress --prefer-dist`: exit 0;
  212 locked packages installed into this candidate's own vendor directory.
  Composer's package junctions target this worktree's packages. No donor vendor,
  autoload override, platform bypass or version update. `composer.lock` SHA-256
  remained `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`.
- `php vendor/bin/phpunit tests/Architecture/MaintainerSkillsTest.php
  --no-coverage --filter the_repository_skills_are_valid`: before repair,
  exit 1, one test, three assertions; exact output showed both stale counts.
- `php vendor/bin/phpunit tests/Architecture/MaintainerSkillsTest.php
  --no-coverage`: count-only repair exposed the two omitted roster paths,
  exit 1, 25 tests / 339 assertions, one failure, 30.421 seconds. After the
  count and roster repair, exit 0, 25 tests / 339 assertions, no skips, 29.869 seconds.
- `php bin/maintainer-skills validate`: exit 0, delivery 3 files and convergence
  15 files. `php bin/check-portable-paths` and `composer hooks:doctor` passed.
- `php bin/check-pr-preflight --base=0b8e53fa27deb112effb9cb960e304d021223157
  --report-json=build/qualification/candidate-consumer-repair/preflight-final.json`:
  exit 0; 42 passed/executed, 0 reused, 0 failed, 0 hosted-required and
  4 not-applicable in 118.2 seconds. The earlier count-only run also passed
  42 gates in 296.2 seconds, but is not the final repair evidence.
  Not-applicable: `check-changelog-shape`, `check-changelog-fragments`,
  `check-ci-workflow-inventory`, `check-ci-roster-conformance`; none of their
  selectors matched. No not-applicable state is counted as a pass.
- `git diff --check`: passed. Pre-commit hooks remain enabled.

Qualification subject: the original candidate plus the immutable repair patch,
SHA-256 `41f9dfbc9ba9170523ca229a759f2b844c3789e0df87b225204e76b82841df27`.
The tested file SHA-256 is
`bc7044233bf7c41d4a9119a318ac36f5189c206ff59e0f365b5e418cc063167b`.
Final preflight's dirty-byte identity is
`1abfdf084bd6ab9eb8451a810b332f015e85df25b2edda8bb0494fc51a85d9b5`.
Local logs, patch and reports are under
`build/qualification/candidate-consumer-repair/`; report SHA-256
`06c513c913f52b2e9444ee59e49d09191ecb1d880f6d2751afdc5bb492206984`.
The portable results above remain usable without that ignored local directory.
This evidence-record append follows qualification; tested source, tests, locks,
tooling and configuration remain identical. Evidence reuse follows
`docs/local-testing-policy.md`; no execution on the eventual commit SHA is claimed.

### Review, changelog and residuals

Independent immutable-patch review approved without findings. Its read-only
discriminator confirmed the exact nineteen-path roster matches, while removing
a reference, adding an unexpected path, or substituting a path at equal count
all fail equality. No candidate was mutated or heavy suite duplicated for review.
The original documentation reference and entrypoint are unchanged, so their
independent approval remains valid. Final evidence prose was independently
approved without findings, including its qualification identities and residuals.

No changelog fragment: this docs/test-only delta changes no consumer-facing
runtime/public surface. The shared agent contract asks for an "appropriate"
fragment; that disposition is interpreted using the specific release-prose
scope in `docs/specs/stability-charter.md` section 8.2 and
`tools/check-changelog-discipline.sh`. The governed `changelog-discipline`
gate passed because no public-surface file changed. No override or bypass was
used, and root `CHANGELOG.md` remains release-owned.

Ordinary landing follows the higher-authority agent contract, workflow spec
and impact-based local testing policy: scoped tests, default preflight, review
and hooks, then bounded main feedback. Full hosted qualification is not an
ordinary landing prerequisite for this batch. The full-profile hosted owners
and exact-SHA release gates remain authoritative when full/release qualification
is requested; the default run does not claim they passed. No additional runtime
suites or consumer overlay were run. Publication, local main integration, main
feedback, package release and deployment remain unperformed and unauthorized
in this repair task. Recheck current heads and concurrent release activity
before any separately authorized landing.
