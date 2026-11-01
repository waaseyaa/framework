# `waaseyaa/ai-tools` audit

November 1 reconciliation: this report and its ledger retain the October 11
assessment identity and limits. At main `c4aff57585`, package source is unchanged
except first-party manifest floors moving from alpha.305 to alpha.308. This is
not fresh installed qualification. The new CMS-free default AI closure requires
delta assessment under [FW-BASIC-APP-STARTER-01](../../change-records/FW-BASIC-APP-STARTER-01.md).

- **Milestone:** in progress. Repair ready: no. Converged: not claimed.
- **Audit state:** in progress. **Remediation state:** not triaged.
- **Base:** `bff4b104d29ba484307dc6655796be10837ff85e`, audited 2026-10-11.
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5; native Windows. Candidate-local Composer dependencies. The published alpha.305 consumer is separate evidence and does not qualify this candidate's split form.
- **Evidence freshness:** current for the bounded source review at the base. A delta review is required if the package changes before full assessment.
- **Owner:** `waaseyaa/framework#3118`. Framework #2653 consumes this boundary evidence without becoming a second package audit program.
- **Profiles applied:** kernel/runtime for discovery and provider wiring; introspection/CLI for tool catalogue and dispatch; domain contracts for principal, schema and audit behavior; distribution for source, split and generated forms. **Not applied fully:** HTTP/UI wire and persistence/execution need the remaining tools and installed consumer paths. Generation/build is not an ai-tools responsibility.
- **Structured ledger:** `docs/audits/packages/ai-tools.ledger.json`: 0 findings, 0 refuted leads, 10 checklist answers, 2 handoffs, 0 decisions (0 open), 2 uncertainties (2 open), 0 probe entries, 6 evidence runs.

## Summary

This is a bounded #2653 supplement, not a full package assessment. All 57 shipped non-test files are inventoried in the ledger; the local operator registry, narrowed visibility, dispatcher, audit wrapper, search adapter and provider wiring received focused review. The package supplies transport-neutral tool contracts to the agent runtime, CLI stdio server and HTTP MCP adapter. Candidate-local tests exercise positive and refusal paths, and the local profile gate finds exactly three default tools among 25 discovered tools. Full export, entity/content/resource, installed HTTP compatibility and split-profile qualification remain open. No ai-tools-owned repair is asserted from the bounded review.

## Charter

- **Owns:** the shared attribute-discovered agent-tool catalogue, tool value and result contracts, visibility registries, schema dispatch, reserve-before-execution audit wrapper, and optional content/search/resource tool adapters.
- **Does not own:** agent-run persistence or model providers (`ai-agent`), MCP framing and client registration (`cli` and `mcp`), the Bimaaji spec corpus, application content policy, or site guidance ownership.
- **Consumers:** `AgentExecutor` in ai-agent, `mcp:serve` in cli, the HTTP MCP bridge, the `full` metapackage, and installed applications that opt into those paths. CLI and HTTP use one dispatch contract with separate transport envelopes.
- **Dependencies:** Composer requires access, entity, entity-storage, foundation, media and publishing. Search is suggested and string-resolved as an optional adapter. The source review did not qualify every declared dependency in a standalone split install.
- **Public surface:** `public-surface.php` declares 10 public symbols. Another 33 source exports have `@api` only and 10 are unclassified. Declaration, caller and lifecycle reconciliation for those exports remains part of the full assessment.
- **Installation profiles:** source kernel composition and framework closure are exercised locally; the split artifact, `full` metapackage and generated application need exact candidate installed-consumer proof before qualification.
- **Evidence it works:** focused candidate-local tests cover registry, dispatch, schema, audit and optional dependency behavior. The actual packaged stdio exercise used a published alpha.305 dependency graph, so it is program journey evidence, not an ai-tools candidate split qualification.

## Roster and structural review

The ledger lists every production file individually: 53 source files, one resource and three package metadata/docs files. The following are the reviewed slice. Every other ledger row is `inventoried`; its classification marks an evidence gap, not a defect verdict.

| Surface | Role | Classification | Level |
| --- | --- | --- | --- |
| `composer.json`, `public-surface.php`, `README.md` | identity, declared API and usage | necessary but under-specified | reviewed |
| `src/Catalogue/AttributeToolRegistry.php` | lazy manifest discovery, duplicate refusal and optional-tool skip | owned and coherent in sampled path | reviewed |
| `src/Catalogue/AutowiringToolContainer.php` | kernel/provider resolution, then reflection fallback | necessary but under-specified | reviewed |
| `src/Registry/*`, `src/Dispatch/*`, `src/Schema/ToolInputSchemaValidator.php` | narrowed visibility, schema dispatch, audited execution | owned and coherent in sampled path | reviewed |
| `src/ContentSearch/*`, `src/Resource/*` | optional search and content-resource adapters | evidence incomplete beyond sampled path | inventoried or reviewed as ledger states |

For a maintainer changing local tool visibility, the route is cli composition, `ToolIdAllowlistRegistry`, `AgentToolDispatcher`, then the tool's own capability check. The allowlist changes discovery, while the tool still authorizes the forwarded principal. For a tool-discovery change, the route is package manifest, `AttributeToolRegistry`, `AutowiringToolContainer`, provider bindings and published descriptor. Duplicate names fail; an absent optional dependency skips that tool and logs a diagnostic. A successful empty catalogue would not prove #2665 acceptance, which must assert the two working default graph tools.

The sampled catalogue and dispatch responsibilities are cohesive. The HTTP MCP bridge remains a live adapter because it preserves HTTP envelopes and log keys; no dead-path conclusion follows from the local stdio path. `AutowiringToolContainer` duplicates generic reflection wiring. Symfony DependencyInjection 7.4.13 is locked and has maintained autowiring, but this package also needs lazy manifest hydration and provider-bound runtime services. U1 calls for an equivalence study before a retain or replace verdict. `ToolInputSchemaValidator` implements a JSON Schema subset; Symfony Validator constraints are not a direct semantic replacement without a mapping contract. The full audit must still inspect unused state, repeated authorization or translation, public PHPDoc, human navigation and all remaining adapters. A passing layer gate does not settle those topics.

## Intake and issue reconciliation

No committed package-audit ledger routed an ai-tools-owned finding into this bounded review. This does not claim exhaustive intake. GitHub and Project reconciliation is recorded under [FW-AI-FIRST-DELIVERY-01](../../change-records/FW-AI-FIRST-DELIVERY-01.md), with publication proposals held for review. #2657 and #2659 are closed with source implementation; #2663 remains a client-configuration design slice; #2661 and #2662 own corpus and cited-search acceptance; #2665 owns the complete packaged journey. #3118 retains the full package convergence assessment. Closed issue state alone was not used as installed qualification.

## Package findings

None confirmed in this bounded slice. The unresolved Symfony wiring comparison and unreviewed exports are U1 and U2, not accepted defects. They must be resolved in #3118 before calling this package assessed or repair ready.

## Cross-package handoffs

| ID | Owned by | Lead | Destination |
| --- | --- | --- | --- |
| H1 | `waaseyaa/bimaaji`, with ai-agent | The default local `bimaaji_search_specs` is discoverable but has no bundled corpus; synthetic-directory success does not meet cited, version-matched search acceptance. | #2661 and #2662 |
| H2 | `waaseyaa/framework` | Live ai-integration and agent-executor specs still describe removed registry/executor locations and contradictory `AgentInterface` lifecycle. | #3118 contract reconciliation |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap and owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Candidate-local registry, dispatcher, schema and provider tests; tool-profile gate. | Full package paths, #3118 |
| framework closure | yes | source | Root candidate-local Composer install and source checks. | Exact installed candidate profile, #3118 |
| metapackage (`full`) | yes | none | No exact candidate split artifact run. | #3118 distribution |
| standalone split | undecided | none | No isolated split-package qualification. | #3118 distribution |
| generated application | yes | none | Published alpha.305 stdio journey is a different dependency identity. | #2665 for journey; #3118 for package closure |

## Profile checklists

Ten ledger answers cover identity and public surface; provider discovery; visibility and principal forwarding; schema and audit refusals; adapter necessity; Symfony reuse; code cohesion; spec trace; and distribution. The reviewed path has real positive and refusal tests. Full content/entity/resource behavior, installed HTTP wire compatibility, complete public-symbol consumers and structural quality are gaps rather than implied passes.

## Decisions and uncertainties

| ID | Uncertainty | What would settle it | Owner |
| --- | --- | --- | --- |
| U1 | Whether maintained Symfony DI can replace the custom reflection resolver while preserving lazy optional-tool and provider-order behavior. | Compare exact source contracts and prove normal, missing-dependency, duplicate, early-provider and split-consumer controls. | #3118 ai-tools audit |
| U2 | Whether every remaining export, adapter and production file is coherent across supported installation forms. | Full 57-file structural, caller and public-surface assessment plus candidate split, HTTP and generated-consumer qualification. | #3118 ai-tools audit |

## Remediation plan

No ai-tools-owned slice is accepted yet. #2653 may advance its independently owned CLI, guidance and client configuration slices while #3118 completes this assessment. Neither a custom-resolver retention decision nor a general package cleanup is a prerequisite for the first #2653 implementation slice.

## Not reviewed and host limits

The bounded pass did not exhaust entity/editorial mutation tools, content resource providers, vector/relationship tools, outbound consumers, all annotations or dynamic split exports. The full audit needs real installed HTTP and standalone split checks. Native Windows prevented six symlink-based `StdioMcpConformanceTest` setups; the corresponding hosted Linux profile owns them. Source tests are not packaged-client qualification.

## Evidence

| Check | Base and dependencies | Result and limit |
| --- | --- | --- |
| Candidate-local Composer install | base above, root lock above | completed; source package paths local |
| LocalOperator unit filter | same | 74 tests, 142 assertions passed; cross-package local identity |
| registry/dispatch/SearchSpecs filter | same | 70 tests, 218 assertions passed; focused source behavior |
| autowiring/attribute/schema filter | same | 38 tests, 117 assertions passed; focused source behavior |
| `bin/check-local-operator-tool-profile` | same | 25 discovered, 3 default, 3 dynamic, 19 withheld |
| `bin/generate-surface-map --check` and `bin/check-package-layers` | same | passed with accepted same-layer cycle warnings |

## Scorecard

- **Elapsed:** bounded lane observed 2026-10-11 20:27 EDT; exact elapsed not retained.
- **Agents:** one boundary investigator; independent #2653 readiness review covers the shared CLI and guidance leads, not a full ai-tools review.
- **Source:** 53 source files, about 6,850 lines; 57 production files inventoried.
- **Consumers:** source CLI and HTTP delegation inspected; no exact candidate installed ai-tools consumer qualified.
- **Intake:** no ai-tools-owned committed ledger intake found in this pass; exhaustive reconciliation pending.
- **Profiles:** kernel/runtime, introspection/CLI, domain contracts and distribution sampled.
- **Verification reversals:** none claimed. No medium-or-higher ai-tools-owned finding is accepted here.
- **Record and ledger:** bounded supplement; no retained reproduction probe.
- **Open:** 0 open decisions; 2 open uncertainties; no ai-tools-owned finding without a filed issue.
