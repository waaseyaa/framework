# `waaseyaa/ai-agent` audit supplement

November 1 reconciliation: this report and its ledger retain the October 11
assessment identity and limits. At main `c4aff57585`, package source is unchanged
except first-party manifest floors moving from alpha.305 to alpha.308. This is
not fresh installed qualification. The new CMS-free default AI closure requires
delta assessment under [FW-BASIC-APP-STARTER-01](../../change-records/FW-BASIC-APP-STARTER-01.md).

- **Milestone:** in progress. Repair ready: no. Converged: not claimed.
- **Audit state:** in progress. **Remediation state:** not triaged.
- **Base:** `bff4b104d29ba484307dc6655796be10837ff85e`, audited 2026-10-11.
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows, candidate-local `vendor`.
- **Evidence freshness:** source checks are current at the base. The separate published alpha.305 consumer used skeleton `b6e839972f1e6eb0e241d4492faeeb31ebfc8952`, consumer lock SHA-256 `6c4cb9d48444651dcb704fd1f06326de2d6e3b947beea562d99e71757c0ac27a` and ai-agent split ref `7bc2de4a0c6adfaa2d5327e4aa7907ebe7273a08`; it does not qualify this candidate.
- **Owner:** `waaseyaa/framework#3118` for package convergence. The local development journey remains under #2653.
- **Profiles applied:** bounded kernel/runtime, introspection/CLI, domain, persistence, HTTP/wire and distribution boundary checks. **Not applied:** generation/build, because ai-agent does not generate a project or client artifact; complete checks in the applied profiles remain open.
- **Structured ledger:** `docs/audits/packages/ai-agent.ledger.json`: 2 findings, 0 refuted leads, 18 checklist answers, 3 handoffs, 1 decisions (1 open), 2 uncertainties (2 open), 0 probe entries, 8 evidence runs.

## Summary

This is the #2653 local operator boundary supplement for ai-agent, not a full package assessment. The 95 shipped non-test files are inventoried, but only the local identity, Bimaaji adapters, provider seam and nearby documentation received behavior review. The package owns the optional agent runtime and its never-persisted local operator principal. CLI owns stdio, and ai-tools owns the transport-neutral catalogue and dispatcher. Source tests support the local principal refusal and least-privilege tool profile. The existing #1608 no-model outcome and package README drift need independent finding verification; neither is an asserted blocker for the first stdio client-configuration slice. The full #3118 assessment must still trace outbound MCP, HTTP agent run, persistence, model providers, public exports, supported installation profiles and downstream callers.

## Charter

- **Owns:** agent definitions and execution, model provider and outbound MCP integration, run persistence and opt-in local operator identity plus five Bimaaji agent adapters. This supplement reviews the latter boundary.
- **Does not own:** stdio framing or command IO, client MCP configuration, project initialization, Bimaaji graph production, or the shared ai-tools registry.
- **Consumers:** provider-discovered optional agent runtime, CLI `mcp:serve` composition, generated development applications and split-package users. Only source composition was directly checked at this base.
- **Dependencies and adapters:** Composer requires ai-tools, bimaaji, access, audit, foundation, persistence and HTTP clients, among others; Wayfinding is suggested. Bimaaji adapters delegate to Bimaaji services. Outbound HTTP MCP is a separate boundary.
- **Public surface:** `public-surface.php` declares 18 public and one internal symbol. Another 56 source exports are `@api` only, seven `@internal` only and seven unclassified. These annotations are not proof of supported caller obligations. The local principal requires explicit CLI development transport attestation and cannot be serialized or installed as ambient account context.
- **Installation profiles and evidence:** candidate-local unit/integration filters, profile gate and surface/layer checks support source behavior. Exact-base generated application, standalone split and packaged client qualification are pending. A published consumer's no-dev install removed ai-agent, consistent with its optional development role, but it is a different base and lock.

## Roster and structural review

The ledger lists every shipped production file: 89 source files, three migrations, and three manifest/documentation/surface files. Reviewed files are the five `LocalOperator` classes, five Bimaaji adapters, `AgentExecutor`, `AiAgentServiceProvider`, `MessagingServiceProvider`, `NullLlmProvider`, `composer.json`, `public-surface.php` and `README.md`. Other roster rows are explicitly **inventoried** with a provisional evidence-gap classification, not a product defect claim.

| Area | Files | Current disposition | Level |
| --- | ---: | --- | --- |
| Local operator identity | 5 | Owned boundary; constructor, serialization and ambient-account refusals source-tested | reviewed |
| Bimaaji tool adapters | 5 | Necessary delegation to graph/search/mutation services; only two graph reads and search are default local tools | reviewed |
| Provider, executor and package metadata | 7 | Composition reviewed; no-model and README leads below | reviewed |
| Remaining production files | 78 | Full cohesion, caller, lifecycle, comment and dead-path audit pending | inventoried |

The local operator directory has a clear single responsibility and the CLI-to-agent-to-ai-tools boundary is intentional. `AutowiringToolContainer` in ai-tools is a custom lazy manifest hydrator. Symfony DI autowiring is available in the candidate lock, but a replacement requires proof of provider-bound runtime services, optional skips and exact graph-tool discovery; no swap is endorsed by this supplement. `ToolInputSchemaValidator` is a JSON Schema subset, so Symfony Validator is not a drop-in substitute without a mapping contract. Outbound MCP wrappers may have live wire and log obligations, and cannot be called dead from this review. Package-wide navigation, PHPDoc accuracy, unused state, repeated mechanisms, competing authorities and adapter necessity remain open in the ledger.

## Intake and reconciliation

This bounded pass did not complete the full committed-ledger/private-brief intake. There are no confirmed incoming ledger items in this supplement. Relevant live ownership is #2653 local development, closed #2658 local principal, open #1608 no-model outcome, #2661 corpus, #2662 cited search and #3118 full convergence. Closed work is treated as source evidence only where its current contract was checked. The live `docs/specs/ai-integration.md` and `docs/specs/agent-executor.md` still describe removed `AgentInterface`, old executor signatures and ai-agent registry ownership; the registry now lives in ai-tools. The spec owner receives this as handoff H3. Package README repair remains an ai-agent item.

## Package findings

| ID | Title | Severity | Confidence | Level | Verified | Disposition | Destination | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| AIAG-RUN-001 | No-model default can complete a run | medium | likely | reviewed | pending tier A | repair after verification | existing #1608 | Obtain independent rederivation and refutation |
| AIAG-DOC-001 | README describes four rather than five Bimaaji adapters | low | confirmed | reviewed | pending tier B | document | proposed S1 under #3118 | Verify, then reconcile package guidance |

### `AIAG-RUN-001`: no-model run outcome

- **Observed:** `MessagingServiceProvider` binds `NullLlmProvider` by default; that provider returns a normal `end_turn` placeholder, and current integration expectations allow Completed. Existing #1608 already owns the concern.
- **Expected and consequence:** absent a usable model, the optional agent runtime should expose an explicit unavailable/refusal outcome. A caller can otherwise mistake a placeholder completion for model work. Local stdio graph inspection does not invoke a model.
- **Confidence and refutation:** source evidence makes the lead likely, but no independent tier-A pair has settled the supported demo/no-model contract or installed impact. It is medium pending that review.
- **Destination and acceptance:** #1608; a no-model installed run must fail with a distinct non-success outcome while a configured model and local inspection retain their behavior. This is independent of the first #2663 descriptor slice.

## Cross-package handoffs

| ID | Owned by | Lead | Destination |
| --- | --- | --- | --- |
| H1 | site-contract, with bimaaji and cli | Fresh site initialization writes root `AGENTS.md`; Codex Bimaaji installation cannot splice its managed region, while force overwrites site ownership | #2664 / #2686 reconciliation |
| H2 | cli, with bimaaji | CLI `run()` bypasses Symfony `configureIO()`, allowing `--no-interaction` to prompt; a skipped unmarked guidance target can exit zero | #3117 |
| H3 | Framework, with ai-tools and ai-agent | Live AI specs retain removed agent and registry contracts | #3118 spec reconciliation |

The H1 and H2 leads were independently source-probed elsewhere in the #2653 audit and are needed for the complete fresh-install journey. They do not change this ai-agent package milestone. The structured ledger carries the exact handoff ownership and affected consumers. No refuted leads were recorded.

## Qualification by profile

| Profile | Supported | Evidence class | Current evidence and gap |
| --- | --- | --- | --- |
| Kernel composition | Optional development | source | Candidate-local principal and provider checks pass; #2665 owns exact-base installed composition. |
| Generated application | Optional development | none | Exact-base generated consumer with ai-agent is unqualified; #2665. |
| Framework closure | Development closure | source | Candidate `composer install` loads it; published consumer no-dev excludes it at another identity. |
| Standalone split | Undecided | none | D1 must set supported scope; #3118 then qualifies it if supported. |

No source test or synthetic fixture is counted as packaged-client qualification. The candidate-local `StdioMcpConformanceTest` filter had six setup errors because Windows denied fixture symlinks; a supported 13-test integration filter passed. Hosted Linux #3096 owns those real-pipe cases. The #2653 journey additionally needs a fresh candidate-exact installation, actual stdio handshake/tool discovery, least-privilege read and mutation refusal, cited docs after corpus work, upgrade/drift/uninstall and no-dev behavior.

## Profile checklist disposition

- **Kernel/runtime and introspection:** provider manifest and local profile answered at source; three default tools, three dynamic and 19 withheld in the 25-tool roster. Search is discoverable but no default corpus is wired.
- **Domain:** local principal refusal, non-serialization and ambient account guard answered at source. General agent execution and no-model outcome remain #1608.
- **Persistence and HTTP/wire:** run repositories, migrations, approvals, worker/broadcast paths, controller and outbound MCP are inventory only.
- **Distribution:** source checks pass; exact-base split, generated application and packaged coding clients need their own gates.
- **Structure and SDD:** local responsibility split is coherent. Full unused-path, duplication, authority, adapter, navigation and comment review remains open; live specs and README need correction.

## Decisions and uncertainties

| ID | Decision or uncertainty | Findings | What would settle it | Who decides |
| --- | --- | --- | --- | --- |
| D1 | Supported ai-agent standalone/metapackage installation profiles | none | Maintainer names supported consumer closure and exact qualification obligations | Framework maintainer |
| U1 | Callers and lifecycle of annotation-only and unclassified exports | none | Full #3118 public-surface/downstream trace | #3118 audit owner |
| U2 | Exact-base installed and actual client behavior | none | Candidate-exact generated/split/Windows/Linux qualification | #2665 and #3118 owners |

## Remediation plan

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| S1, proposed only | AIAG-DOC-001 | README distinguishes five adapters from three local defaults, states corpus readiness accurately, and linked live specs name the ai-tools registry | #2661 corpus contract decision |

#1608 already owns AIAG-RUN-001. S1 is not a filed issue or permission to implement. The full package assessment and verification determine repair readiness; unrelated whole-package cleanup does not block the first #2663 design slice.

## Not reviewed and host limits

Outbound HTTP MCP, model providers, agent HTTP run, persistence/worker/approval/broadcast, Wayfinding, public export callers and complete structural quality remain unreviewed. Candidate-exact installed and standalone split consumers were not run. Native Windows denied six symlink fixture setups; hosted Linux owns that check. No application or runtime files were changed in this audit supplement.

## Evidence

| Command or probe | Base and dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- |
| LocalOperator unit filter | base and lock above | native Windows | local principal source behavior | 74 tests, 142 assertions passed |
| registry/dispatch/SearchSpecs unit filter | same | native Windows | shared catalogue and synthetic search | 70 tests, 218 assertions passed |
| CLI stdio unit filter | same | native Windows | source command/protocol boundary | 43 tests, 203 assertions passed |
| autowiring/registry/schema filter | same | native Windows | construction/schema boundary | 38 tests, 117 assertions passed |
| LocalOperator/HTTP-SAPI/correlation integration filter | same | native Windows | source integration boundary | 13 tests, 100 assertions passed |
| `php bin/check-local-operator-tool-profile` | same | native Windows | default and withheld roster | passed, 3/3/19 of 25 |
| `php bin/generate-surface-map --check` | same | native Windows | declaration sync | passed |
| `php bin/check-package-layers` | same | native Windows | static layer dependencies | passed, accepted same-layer cycle warnings |

The commands and source identities are expanded in the ledger. These runs overlap and are not additive unique test counts. The boundary investigator's scratch assessment and roster were consolidated into this committed record; independent finding verification and final record critic remain pending.

## Scorecard

- **Elapsed and agents:** bounded investigator began 2026-10-11 20:27 EDT; consolidated elapsed unavailable; one boundary agent; subagent tokens unavailable.
- **Size and consumers:** 89 source files, 95 production files; source lines not measured; candidate source composition reviewed; no candidate-exact installed consumer claimed.
- **Intake and profiles:** zero confirmed incoming ledger items in this bounded pass; six profile families partially applied.
- **Findings:** two package-owned leads, one medium and one low; three cross-package handoffs. All finding verification remains pending, with no recorded reversals.
- **Artifacts:** zero retained finding probes; scratch source probes belong to other package boundaries; record length 127 lines and 2,125 words; ledger 63,162 bytes.
- **Open:** 1 open decision; 2 open uncertainties; AIAG-DOC-001 has no filed bounded issue.
