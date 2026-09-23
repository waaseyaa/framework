# FW-CLI-CONVERGENCE-01 — cli package convergence

- Audit baseline: `5196f173b06bac698f14d292c7b827f2ddac388f`
- Current base: `fa276b52a4785dfe40d16acd7c5197054592b4a0` (no `packages/cli`
  or `packages/foundation/src/Kernel` change since the audit baseline)
- Forge mirror: `waaseyaa/framework#3117`
- Parent program: `waaseyaa/framework#3118`; consumer-driven slice
  `waaseyaa/framework#3122`
- Related: `waaseyaa/framework#2821` (dependency closure), `#2844`
  (application generation), `#2984` (ingestion ownership), `#3007`
  (route:list truthfulness), `#3075` (Deptrac adoption), `#3110` (schema
  authority), `#3121` (graph:dump under ConsoleKernel), `ROUTE-METADATA-01`
- Contracts: `docs/specs/cli-kernel.md`,
  `docs/specs/cli-symfony-console-migration-plan.md`
- Branch: `claude/fw-cli-convergence-3117`
- Worktree: `C:\dev\waaseyaa\framework-worktrees\cli-convergence-3117`
- Authority: change record, charter, scoped implementation, tests, review,
  and PR for the work packages below. No tag, release, split publication,
  deployment, production mutation, or unrelated cleanup authority. Landing
  each PR requires the maintainer's separate approval.

## Outcome

Make `waaseyaa/cli` a reviewable operator-transport package with an explicit
charter, intentional dependencies, truthful public declarations, one
documented process-exit contract, lossless machine output, registration that
preserves supported command metadata, and source plus installed-consumer
evidence. Storage and schema authority, authorization, lifecycle rules, and
durable mutation semantics remain with their domain packages.

## Charter (proposed, from the #3117 audit)

- **Owns:** operator command presentation, parsing, discovery, registration,
  transport, diagnostics, entrypoint behavior, and adapters that compose
  lower-layer operations into commands.
- **Does not own:** storage and schema authority, authorization policy,
  entity lifecycle, durable mutation semantics, route composition (Foundation
  per `ROUTE-METADATA-01`), or graph description (Bimaaji).
- **Currently housed but needing an explicit disposition:** site and project
  generation (36 Site, 10 ProjectInit files), repository Admin build tooling
  (25 AdminBuild files), migration runtime helpers used by generated code
  (`BackfillHelper`), ingestion (8 files, owned by #2984), and MCP transport.
  File count alone is not an extraction argument.
- **Special boot profiles:** bare invocation/version (no boot); maintenance,
  `db:init`, `site:init|doctor|apply`, `project:init` (pre-runtime);
  field-access maintenance (restricted boot); mutation-authority backfill
  (dedicated boot); `install:init`, `schema:sync`, `migrate*` (schema boot);
  everything else via `bootForCli()` and ordinary provider discovery.

This charter is committed in `packages/cli/README.md` by WP1. Until then it is
a proposal recorded here.

## Baseline inventory (audit, not qualification)

276 production PHP files; 28 declared providers including the empty root
provider; 30 direct Waaseyaa runtime requirements; 41 `public-surface.php`
entries; 146 class-level `@api` annotations without a matching declaration;
129 literal `HandlerCommand` declarations (syntax count, not an authoritative
runtime command roster).

## Finding ledger

| ID | Area and observed evidence | Consequence | Disposition and owner | Acceptance evidence | Issue |
| --- | --- | --- | --- | --- | --- |
| CLI-IO-001 | `SymfonyCommandIO::writeln()` applies `plainText()`, removing console-style tags. `debug:context --entity-id=<info>literal</info>` emits `entity_id: "literal"`, exit 0. The same corruption reproduces through `GraphDumpHandler` via `RoutingIntrospectionProvider`. `writeRaw()` preserves bytes | Machine JSON silently loses literal values that resemble formatter markup | Repair: every machine-output path writes losslessly; human styling stays separate; diagnostics on stderr. Trace each JSON handler individually | Real-application round trip of nested, non-empty values containing tag-like text for stdout and file output; discriminating test fails on `writeln()` | #3117, #3121 |
| CLI-EXIT-001 | `debug:context --relationship-counts=bad` returns 2 via `CommandTester`, 1 via `WaaseyaaConsoleApplication` with real `ArgvInput`. `normalizeExitCode()` maps every nonzero except 130 to 1, while `cli-kernel.md` also promises usage errors return 2 | Scripts cannot rely on the documented usage status; tests at handler level pass while the process contract differs | Decide one backward-compatible process contract, document it in `cli-kernel.md`, reconcile handlers and tests. Do not widen historical codes 3/4 into a public contract by accident | Process-level tests for success, parser error, handler validation, domain failure, exception, and interruption (130) | #3117 |
| CLI-REG-001 | `HandlerCommand::withContainer()` rebuilds from constructor metadata only; an instance with alias, `hidden=true`, and help loses all three when `ConsoleApplicationFactory` binds it | Provider-declared aliases, hidden status, and help disappear from list, help, and invocation | Repair for the explicitly supported metadata set; document that set as the extension contract | `list`, `help`, and alias-invocation discriminators through the real factory | #3117 |
| CLI-REG-002 | The factory binds a directly supplied `HandlerCommand` but returns container-resolved service-ID commands unbound; invocation exits 1 with `requires a handler container` | A command is advertised but cannot run, although its handler is available | Define uniform instance/service-ID/FQCN behavior, or require prebound descriptors with early validation | Success and missing-service refusal for each registration form; no false availability | #3117 |
| CLI-ROUTE-001 | `route:list` (`MiscBServiceProvider`) builds routes with `BuiltinRouteRegistrar` and no application providers, and prints only method/path/name | Operators see an incomplete route table without declared access posture | Consume the Foundation route metadata snapshot once `ROUTE-METADATA-01` lands; do not duplicate route construction in CLI | Installed-consumer route with application provider appears with declared access | #3007, #3122 |
| CLI-PUBLIC-001 | 41 declarations versus 146 unmatched `@api` annotations; reachability prose drift | Compatibility promises are ambiguous | Reconcile each family against real consumers; no blanket promotion or annotation removal | Public-surface parity plus consumer evidence per family | #3117 |
| CLI-ARCH-001 | No scoped dependency model; generated migrations import and call CLI `BackfillHelper` at runtime | Extraction or layering changes can break historical generated files | Scoped Deptrac model with allowed/forbidden/uncovered controls; compatibility plan before any move | Deptrac JSON report with zero uncovered plus controls; generated-migration execution test | #2821, #3075 |

Foundation `ConsoleKernel` composition under `/src/Kernel/` is an existing
explicit boundary exception in package-layer tooling. It is not a new finding.

## Explicit exclusions

- Application generation and scaffolding stay with #2844 and its children.
- Ingestion ownership stays with #2984.
- Programmatic schema adoption stays with Foundation (#3110); applications
  must not be forced to install CLI to adopt a schema.
- Route composition design stays with `ROUTE-METADATA-01` (#3123).
- Repository-wide Deptrac parity and retirement of `bin/check-package-layers`
  stay with #3075.
- Mutation-outcome qualification across CLI/MCP stays with #3035.
- No release, split publication, deployment, or production operation.

## Work packages

| Work package | Scope | Status |
| --- | --- | --- |
| WP0 | Worktree, durable change record, changelog fragment | This candidate |
| WP1 | Charter in `packages/cli/README.md`; authoritative command roster generated from the real application; public-surface dispositions | Not started |
| WP2 | Scoped Deptrac model and controls for `packages/cli` | Not started |
| WP3 | CLI-IO-001 lossless machine output (first repair; also unblocks #3121) | Not started |
| WP4 | CLI-REG-001 and CLI-REG-002 registration repairs | Not started |
| WP5 | CLI-EXIT-001 process exit contract and spec reconciliation | Not started; needs maintainer decision on the contract |
| WP6 | Source, installed/no-dev, optional-package, and native-host qualification | Not started |

Each work package is a separate review candidate. WP3 and WP4 do not depend
on WP1/WP2 and may proceed first if the consumer unblock requires it.

## Evidence ledger

| Candidate | Evidence | Result |
| --- | --- | --- |
| Audit baseline `5196f173b` | 29 unique focused tests / 125 assertions across `SymfonyConsoleRuntimeTest`, `OptionalPackageConsoleCommandsTest`, `DebugContextHandlerTest`, `HealthReportHandlerTest`, `CacheClearHandlerTest` (PHP 8.5.5, native Windows) | Pass; the four boundary probes reproduce all four #3117 findings despite this green baseline |
| Audit baseline `5196f173b` | `route-cli-probe` through real `GraphDumpHandler`, `RoutingIntrospectionProvider`, `SymfonyCommandIO` | Reproduces CLI-IO-001 on the graph path |
| Base `fa276b52a` | `git diff 5196f173b fa276b52a -- packages/cli packages/foundation/src/Kernel` | Empty; audit findings apply unchanged |

Probe reports from the audit live outside the repository; the facts above are
mirrored in #3117. They are audit evidence, not installed-consumer
qualification.
