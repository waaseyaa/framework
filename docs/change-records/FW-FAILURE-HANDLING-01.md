# FW-FAILURE-HANDLING-01: failure-handling convergence

Status: audit preparation, 11 October 2026. Parent: FW-PACKAGE-CONVERGENCE-01
and #3118. Audit owner: [#3203](https://github.com/waaseyaa/framework/issues/3203). This record coordinates a bounded assessment and supplements, not
another framework umbrella or findings ledger. Runtime remediation is pending.

## Baseline and custody

Planning base: `bff4b104d29ba484307dc6655796be10837ff85e`. Work is isolated on
`codex/failure-handling-audit-guidance`; native Windows Git entrypoint is
`C:/Program Files/Git/cmd/git.exe`. Package source is unchanged by this candidate.
The audit must revalidate its own source and dependency identities before probes.
Local candidates are not canonical main, installed artifacts or published proof.

## Why this audit

At the planning base, CLI owns Throwable rendering and exit normalization while
disabling Symfony exception catching. Foundation has HTTP/bootstrap catches and
logging paths; error-handler has separate exception renderers. Its coverage row
is not assessed. This inventory warrants a wiring and ownership assessment; it
does not prove every custom path is unnecessary or that a renderer is unreachable.

The debugging spec's blanket preference for native mechanisms conflicts with
current maintained-infrastructure governance. This preparation removes that
preference without pretending the replacement design has been decided.

## Scope, owners and audit supplements

| Surface | Existing owner / evidence | Required next evidence |
| --- | --- | --- |
| error-handler | No prior package assessment; closed #733/#734/#735 are implementation history | Full charter/file roster, real provider/renderer reachability, supported installation profiles and Symfony/component-fit decision |
| Foundation HTTP/bootstrap/logging | #3123; #2859 lifecycle; #3198 diagnostics | Bounded supplement covering failure before/after handler construction, competing renderer paths, logging/redaction and failed/reused kernel behavior |
| CLI | #3117 | Bounded supplement separating Symfony IO lifecycle from exception policy, real process exit/output and failure while rendering/logging |
| API/MCP and AI write-tool boundaries | #3014 sanitization; #3035 committed outcomes; #2733 domain refusals | Trace actual callers and reuse existing private evidence; qualify envelope/status, safe diagnostic ownership and durable outcomes without duplicating repairs |
| Queue and worker recovery | #2741/#2743 and J4 | Apply failure profile when continuing those audits; retain their delivery/settlement contracts, interruption limits and existing findings |
| J1 installation/homepage | #3199 | Reuse J1-D HTTP/bootstrap/no-dev failures as composition witnesses; do not wait for unrelated package convergence |
| Structural duplication | #2985 | Route verified competing authorities here with the existing source IDs; no duplicate inventory |

#2666 remains broader developer diagnostics design, not a prerequisite for fixing
existing runtime errors. Debug/toolbar and other packages enter only when actual
call chains establish involvement. This table identifies supplemental work; it
does not mark any package assessed, any finding resolved, or private evidence
public. Preserve existing package records and frozen evidence.

## SDD and component decisions

Read debugging-dx, infrastructure, http-entry-point, middleware-pipeline,
cli-kernel, api-layer/jsonapi, mcp-endpoint, content-publishing and applicable
execution specs as paths become relevant. Record contradictions before repair.
Each custom generic mechanism gets a supported-version source/test/documentation
comparison, retained domain policy, installation impact and disposition. Symfony
Console, ErrorHandler and HttpKernel are candidates to evaluate, not a mandate
to migrate the whole kernel. Where Symfony delegates, evaluate the established
integration rather than inventing a substitute.

Decide intended status/exit/envelope behavior, diagnostic/redaction ownership,
debug policy and pre-/post-commit recovery in the owning specs before code changes.
Historical custom behavior is not automatically an enduring requirement. Retained
custom machinery requires an evidenced gap, owner and review trigger. Avoid a new
universal error abstraction or general observability platform.

## Sprint sequence and acceptance

1. **Assessment ready:** revalidate the base, all relevant issues/PRs and package
   ledgers; inventory error-handler completely and trace supported failure paths
   across Foundation and CLI. Use the existing failure-handling profile. Record
   boundaries not reached and supported profiles not qualified.
2. **Design checkpoint:** disposition component fit, competing/dead paths and spec
   contradictions. Publish proposed enduring contracts in owning specs, with
   unresolved decisions explicit. Do not copy observed defects into requirements.
3. **Repair readiness:** map confirmed findings to existing issues; create a new
   bounded repair only if no owner exists. Each repair names a failing control,
   affected contracts, removal scope, installation effects and qualification.
4. **Authorized remediation:** capture discriminating failures, implement the
   smallest coherent slice, preserve success/refusal controls, obtain independent
   immutable review and relevant exact-candidate checks. Trace public outcome,
   durable effects and safe diagnostics together, including handler failures,
   committed/uncertain writes and applicable retry/restart behavior.
5. **Closeout:** reconcile package ledgers, coverage, owning specs/docs and GitHub
   Project 4. Remove superseded live guidance after reference/obligation checks.
   Full error-handler assessment and completed bounded supplements are separate
   from repair completion and supported installed qualification.

The CLI IO candidate `ee65850ff6ff0f3a1184ce24f3d9bbbe342efc7a` is separate local
evidence. It restores parent Console IO lifecycle while keeping custom exception
policy; it neither chooses the final architecture nor proves fresh dependency
resolution. Preserve it and concurrent work. No alpha publication, merge, push,
release, deployment or consumer changes are part of this preparation.

## Evidence and review

Preparation changes skills and planning/spec policy only. Runtime audits and
component-fit probes have not run. Independent candidate review remains pending
for a user-selected reviewer; self-review is not independent evidence.

Candidate-local locked Composer installation completed on native Windows/PHP
8.5.5; lock SHA-256 is
`8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`.
Focused validation of the preparation delta:

- `php bin/maintainer-skills validate` and both skill-creator quick validators
  passed. The new profile is discovered as the seventeenth convergence file.
- MaintainerSkillsTest and PackageConvergenceCoverageIndexTest passed together:
  29 tests, 1,229 assertions. Initial failures detected the added profile missing
  from the explicit file roster/count; updating that roster made the rerun pass.
- `php bin/check-package-coverage-history` verified nine audited rows and four
  commit-sourced copies against main. The new ownership assignment leaves
  error-handler not assessed and remediation not triaged.
- Changed Markdown local-target check passed (46 links at that checkpoint);
  changelog-fragment validation and `git diff --check` passed.

These checks qualify documentation structure and evidence bookkeeping, not
runtime error behavior or installed consumers. No runtime suite is claimed.

GitHub preparation published #3203 as a native child of #3118 and a Project 4
item: P1, Todo, Ready for assessment, Integrity & Runtime, Release Foundation.
Coordination sections were added to #3118/#3123/#3117/#3199/#3198/#3014/#3035/
#2733/#2743, retaining their existing bodies, acceptance and states. These public
updates explicitly identify the local documentation candidate as unlanded.

### Review handoff

Review the immutable candidate on `codex/failure-handling-audit-guidance` against
the planning base above. Read the repository guidance and #3203. Check that the
new failure profile strengthens the two existing maintainer skills without
duplicating runtime specs or predetermining a Symfony migration. Verify the
debugging spec correction, roadmap sequencing, existing issue ownership and
coverage state are truthful. Check changed links and maintainer-skill/coverage
validators. Do not change runtime code, dispatch implementation, merge, push,
publish packages or release. Return actionable findings and evidence; the
maintainer selects the reviewer and model.
