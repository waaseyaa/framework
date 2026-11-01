# FW-BASIC-APP-STARTER-01

Status: Planning delivery approved for main; implementation design remains proposed. Updated November 1, 2026.

Russell requested a basic app starter sharing setup infrastructure with the CMS
starter while excluding CMS packages from the basic app. Russell subsequently
agreed that both starters include AI by default: development support plus shared
runtime integration contracts, with providers explicitly configured. AI dependency
separation is now required work, superseding the earlier exclusion/deferment. [Draft contract](../specs/basic-app-starter.md)
owns the proposed behavior; [readable planner](../plans/basic-app-starter.html)
summarizes it. Russell authorized reconciliation and landing of this plan on November 1.
No starter implementation was dispatched. The existing reviewed CLI IO repair
is carried forward separately in the same integration candidate; it does not
implement starter dependency separation.

## Audit coordination added November 1

Russell requested core audit coverage alongside starter work. The
[readiness checklist](../audits/packages/core-starter-readiness.md) records the
live 22-package coverage snapshot and proposed assessment destinations under
#3118. Six cited remediation/audit issues and #3118 were checked live and remain
open. No GitHub state, canonical coverage status or assignment was changed.

Complete core assessments and reconcile existing findings while starter work
progresses in bounded slices. Extend coverage to the final default CLI/AI and
development closures. BAS-14 is an additional readiness gate; installed-app
success cannot substitute for audit evidence. Required repairs, review and
accepted residual decisions retain their existing owners and acceptance.

## Stage and sequence

Current stage: design and dependency boundary. Next step: reconcile assessment ownership and ratify slice 1's
dependency/ownership design before implementing the shared starter extraction.
Implementation, review, integration, publication and consumer qualification are
distinct later gates. The Framework roadmap remains the sequencing authority;
this proposal does not displace the active dependency-maintenance work.

| Slice | Scope and completion boundary |
| --- | --- |
| 1. Establish lean setup, HTTP and AI ownership | Trace the installed closures and unconditional service construction. Reconcile the core checklist and extend it to final CLI/AI dependencies under #3118; name assessment executors before dispatch. Design the minimal CLI/provider graph, CMS-free AI development closure, runtime AI contract ownership and basic manifest defaults. Reconcile affected canonical contracts, including HTTP entry-point prose. Capture acceptance fixtures that expose today's CMS leakage. Resolve all technical choices needed by slice 2. |
| 2. Qualify shared runtime/setup | Implement only the approved dependency separation using existing discovery and initialization services. Prove BAS-01, 03-07 and 11-13 in installed consumers of both profiles, retaining CMS positive controls and the ADR-022 development/production boundary. Independently review shared architecture, activation and access changes before integration. Coordinate core/dependency assessments and existing repairs without duplicating their owners. |
| 3. Assemble two starter distributions | Extract shared source, add thin app/CMS profiles, migrate all existing skeleton-path consumers and preserve artifact rules. Prove deterministic assembly, full basic and CMS creation journeys, and supported native-host behavior (BAS-02, 08-09). |
| 4. Prepare delivery, then qualify publication separately | Extend existing skeleton publication ownership to both profiles, pin every first-party direct requirement appropriately, retain immutable tags and provenance. Require BAS-14 package readiness as well as candidate archive qualification first. Register/publish only with later authority, then prove published create-project and locked no-dev flows (BAS-10). |

Do not split a slice into arbitrary broad package cleanup. If CLI or AI extraction
exposes larger architecture work, record the specific prerequisite and re-scope
before expanding. No new metapackage is assumed necessary.

## Proposed diff forecast

Paths are relative to Framework. Added paths are proposals, not scaffolds to
create now. Conditional paths require the ownership decision in slice 1.

| Slice | Status | Path | Responsibility |
| --- | --- | --- | --- |
| Planning | Added now | `docs/specs/basic-app-starter.md` | Draft dependency, shared-source and acceptance contract. |
| Planning | Added now | `docs/change-records/FW-BASIC-APP-STARTER-01.md` | Scope, slices, evidence and decisions. |
| Planning | Added now | `docs/audits/packages/core-starter-readiness.md` | Core roster, canonical evidence links, proposed assessment destinations and completion criteria under #3118. |
| Planning | Modified | `docs/roadmap.md` | Link the planned starter and its convergence readiness dependency without reprioritizing active work. |
| Planning | Added now | `docs/plans/basic-app-starter.html` | Offline readable sidecar. |
| 1 | Modified | `docs/specs/site-golden-path.md`, `cli-kernel.md`, `http-entry-point.md`, `package-discovery.md` | Reconcile the lean profile with existing owners before implementation. |
| 1 | Conditional | `docs/adr/025-unified-artifact-generation-authority.md` | Amend the package-materialization decision if the lean profile requires it. |
| 2 | Modified | `packages/cli/composer.json`, `packages/cli/src/Handler/`, `packages/cli/src/Site/` | Remove mandatory CMS coupling while retaining one setup authority. Exact files follow tracing. |
| 1 | Modified | `docs/specs/ai-integration.md`, `docs/specs/bimaaji-install.md` | Specify default AI support for both profiles and CMS-free operation. |
| 1 | Conditional | `docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md` | Reconcile package ownership while preserving its development-only authority boundary. |
| 2 | Modified | `packages/ai-development/composer.json` and affected AI dependency manifests/providers | Remove CMS coupling from default AI tooling without dropping its capabilities. Exact transitive owners follow tracing. |
| 2 | Conditional | Existing runtime AI contract/provider owners and their tests | Extract shared runtime contracts only where necessary; preserve no-dev operation and avoid a second AI abstraction. |
| 2 | Modified | `packages/foundation/src/Kernel/HttpKernel.php` | Make optional integrations respect installed capabilities without bypassing safeguards. |
| 2 | Conditional | Owning domain provider/command files and manifests in `packages/` | Relocate CMS command ownership, respecting package layers. |
| 2 | Added | `tests/PackagedForm/check-basic-app-starter` and bounded fixtures | Exercise real installed dependencies, HTTP, storage, default AI introspection, no-dev runtime AI, provider configuration and refusal behavior. |
| 2 | Modified | `packages/cli/tests/Unit/Handler/InstallInitHandlerTest.php`, `packages/cli/tests/Unit/Site/SiteInitializationServiceTest.php` | Cover meaningful lifecycle failures touched by the separation. |
| 3 | Added/moved | `starters/shared/`, `starters/app/`, `starters/cms/` | Canonical common files, shared AI defaults and deliberately small profile differences. |
| 3 | Added | `tools/assemble-starter.php` | Deterministic offline distribution composition with collision checks. |
| 3 | Modified/retired | `skeleton/`, its archive/audit consumers and `tests/Architecture/SkeletonApplicationAnatomyTest.php` | Move the single source of starter truth and migrate all consumers together. |
| 3 | Generated/ignored | Temporary assembled app and CMS directories | Test/release inputs; no second hand-maintained source tree. |
| 3-4 | Modified | `tools/sync-skeleton-requirements.php`, `.github/workflows/sync-skeleton.yml`, affected CI and version-sweep tools | Preserve one publication owner, compatible version constraints and package provenance for both profiles. |
| 4 | Modified | Starter READMEs and existing onboarding guidance | Document only qualified creation commands and platform behavior. |
| Each implementation slice | Added | `changes/unreleased/FW-BASIC-APP-STARTER-01.<slice>.md` | Governed release fragment under repository naming rules. |

## Evidence and review

Initial source inspection and static first-party require-closure traversal used
`1ad01b48fcd74160dc1b97156b09e3deda436ae4`. November 1 reconciliation refreshed
the planning base to `c4aff57585a8a7bd13e27e77d710f10dcc0cf662`; the relevant
core/CLI/HTTP/AI source and dependency graph observations are unchanged. No fresh installation or behavior tests
were run during planning. The active checkout's unrelated edits were preserved
by creating branch `codex/basic-app-starter-plan` in an isolated worktree using
`C:/Program Files/Git/cmd/git.exe`.

Planning self-review: agreed scope is separated from proposals; the package
exclusion contract covers dev dependencies; existing production refusal and
generation ownership remain intact; current main and published acceptance are
not inferred from this baseline. Required independent implementation review
remains pending and has not been dispatched.

Artifact validation passed: all local document links resolve, the HTML has no
remote resource references, the fourteen acceptance IDs are present, and the planning
artifacts contain no em dashes. The isolated worktree contains planning documents
and a roadmap link only; no product code was changed. Desktop/narrow browser rendering has not been
visually checked. No application tests were warranted for these planning files.

The November 1 audit supplement is a live index/issue-state check, not a fresh
runtime audit. Checklist links point to the existing canonical findings and
evidence; all readiness rows remain pending.

Supplement validation: all 22 checklist package names match the transitive core
require closure at the planning baseline; all 14 BAS criteria are unique, local
links resolve and the HTML remains offline. An initial validation expression
omitted numeric package-name characters (i18n); correcting that checker produced
the complete 22-row match without changing the roster. No runtime audit or
consumer test was run for this documentation update.
