# Waaseyaa

`docs/governance/agent-contract.md` is the canonical cross-agent operating
contract. Read it before substantive work. This file is the Claude Code adapter
for architecture, subsystem routing, commands, and repository-specific gotchas;
it cannot expand authorization or weaken the shared contract.

## Project Structure
- Monorepo: PHP packages live in `packages/`; the consumer-facing metapackages are `core` (engine), `cms` (`core` + content types), and `full` (expanded reusable tooling without opt-in domains). The JS admin SPA lives in `packages/admin/` and has no `composer.json`.
- **Metapackage menu vs. `waaseyaa/framework`:** downstream consumers `composer require` one curated metapackage (`waaseyaa/core`/`cms`/`full`) — see `docs/roadmap/packagist-publishing-plan.md` and ADR-004 §8. The dev `skeleton/` requires `waaseyaa/framework`, whose default dependency graph follows the same opt-in-domain boundary even though the monorepo contains every package. The metapackage require-graphs are version-swept by `bin/sync-internal-versions`, split-mirrored by `split.yml`, and guarded by CI: `ci/core-only-boot` boots the kernel on `waaseyaa/core` alone (`tests/CoreOnlyBoot/boot.php`), `ci/packaged-form` runs a consumer that requires `waaseyaa/core` (`tests/PackagedForm/`), and `MetapackageSmokeTest` checks autoloadability of all three.
- 7-layer architecture (Foundation → Core Data → Content Types → Services → API → AI → Interfaces)
- Each package has its own `composer.json` with path repository references
- Root `composer.json` uses `self.version` for all waaseyaa/* siblings — it is published to Packagist as `waaseyaa/framework`, so consumers receive the manifest verbatim. `self.version` resolves to `dev-main` locally (path repos) and to the exact tag version when crawled by Packagist (e.g. `0.1.0-alpha.170`), giving consumers exact-matching siblings without a release-time rewrite step. (#1382)
- Composer policy is codified and gated via `bin/check-composer-policy`:
  - `config.sort-packages` must be `true` in all first-party `composer.json` files
  - `@dev` is forbidden everywhere (CP002) — published artifacts cannot resolve it
  - `self.version` is allowed only in root `composer.json` (CP006) — sibling metapackage shape
  - wildcard internal constraints for `waaseyaa/*` are forbidden (CP003)
  - internal `waaseyaa/*` constraints in `packages/*/composer.json` must equal `^<checked-out VERSION>` (CP-NEW), enforced cross-file against the tracked `VERSION` file so a freshly fetched release commit is deterministic even before its tag ref is fetched; repositories without `VERSION` retain the legacy `git describe --tags --abbrev=0 --match='v*.*.*'` fallback. The literal advances automatically at each release-cut via `bin/sync-internal-versions` in `release-cut.yml`.
  - package-local path repositories and internal `require`/`require-dev` entries must correspond in both directions (CP007), preventing stale local resolutions and undeclared package-local paths
- Authorization pipeline in `public/index.php`: SessionMiddleware → AuthorizationMiddleware. Session always sets `_account` on request; authorization reads it.
- Route access control via route options: `_public`, `_authenticated`, `_session`, `_permission`, `_role`, `_gate` — checked by `AccessChecker`
- Field-level access: `FieldAccessPolicyInterface` (companion to `AccessPolicyInterface`). Classes must implement both — `EntityAccessHandler` finds field policies via `instanceof` check. Open-by-default: Neutral = accessible, only Forbidden restricts.
- Access result semantics differ by level: entity-level uses `isAllowed()` (deny unless granted), field-level uses `!isForbidden()` (allow unless denied). This asymmetry is intentional.

## Orchestration

When working on files matching these patterns, retrieve the spec for deep context. **Orchestration skills are not Skill-tool skills**: The `waaseyaa:*` entries below are conceptual routing hints — read the listed `docs/specs/*.md` files (Read tool or `rg` in `docs/specs/`), not the `Skill` tool, unless you explicitly load a specialist skill.

| File pattern | Specialist skill | Cold memory spec |
|---|---|---|
| `packages/entity/*`, `packages/entity-storage/*`, `packages/field/*` | `waaseyaa:entity-system` | `docs/specs/entity-system.md` |
| `packages/field/src/Classification/*`, `packages/field/src/Entity/{ClassificationLabelDefinition,RetentionPolicy}.php`, `packages/admin/app/pages/classification/*` (classification labels, inheritance, clearance/hold access, retention jobs) | — | `docs/specs/classification-and-retention.md` |
| `packages/entity-storage/src/{Schema/TranslationSchemaHandler,Schema/RevisionTableBuilder,Driver/RevisionableStorageDriver,Listing/TwoAxisFilterResolver,Revision/RevisionPruningPolicy,Exception/StorageMigrationException}.php`, `packages/access/src/Policy/RevisionPolicyComposition.php` (two-axis storage: revisionable × translatable) | — | `docs/specs/revision-system-unified.md` (LIVE canonical — read first), `docs/specs/entity-storage-two-axis.md` (SUPERSEDED — M-004 `vid` model, retired alpha.196), `docs/cookbook/translatable-revisionable-entities.md`, `docs/upgrade-notes/two-axis-storage.md` |
| `packages/config/*` (active store, runtime read API) | `waaseyaa:entity-system` | `docs/specs/entity-system.md` |
| `packages/config/src/{Sync,Dependency,Audit,Backend}/*`, `packages/cli/src/Command/Config/*` (CMI: sync store, `config:*` CLI, `config.audit`) | — | `docs/specs/config-management.md`, `docs/cookbook/config-sync.md`, `docs/adr/018-configuration-management-sync.md` |
| `packages/access/*`, `packages/user/src/Middleware/*` | `waaseyaa:access-control` | `docs/specs/access-control.md`, `docs/specs/field-access.md` |
| `packages/auth/*` | `waaseyaa:access-control` | `docs/specs/access-control.md` |
| `packages/api/*`, `packages/routing/*` | `waaseyaa:api-layer` | `docs/specs/api-layer.md`, `docs/specs/jsonapi.md` (cast-aware attributes) |
| `packages/wayfinding/*` (flagship Wayfinding: anchor registry/catalog, beacon delivery, trails, MCP write tier) | — | `docs/specs/wayfinding.md`, `kitty-specs/wayfinding-01KVGH5X/spec.md` |
| `packages/api/src/Controller/BroadcastStorage.php`, `packages/foundation/src/Http/Router/BroadcastRouter.php`, `packages/foundation/src/Kernel/EventListenerRegistrar.php` (SSE broadcasting) | — | `docs/specs/broadcasting.md` |
| `packages/attachment/*`, `packages/structured-import/*`, `packages/field/src/Form/*`, `packages/field/src/Attribute/BundleTemplate.php`, `packages/field/src/Attribute/FieldTemplate.php`, `packages/field/src/BundleTemplateCompiler.php`, `packages/routing/src/EntityDeepLinkRouteBuilder.php`, `packages/api/src/Controller/FieldAutoSaveController.php` | — | `docs/specs/work-surface.md` |
| `packages/admin/*` | `waaseyaa:admin-spa` | `docs/specs/admin-spa.md` |
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md`, `docs/specs/authoring-assist-contract.md`, `docs/specs/semantic-refresh-trigger-contract.md` |
| `packages/ai-schema/*` | — | `docs/specs/ai-schema.md` (JSON Schema generation + capability-registry contract sketch) |
| `packages/foundation/src/Ingestion/*`, `defaults/ingestion.*` | `waaseyaa:ingestion` | `docs/specs/ingestion-defaults.md`, `docs/specs/ingestion-validator-contract.md`, `docs/specs/ingestion-validation-gates-contract.md`, `docs/specs/ingestion-fixture-pack-contract.md`, `docs/specs/ingestion-editorial-dashboard-contract.md`, `docs/specs/source-priority-merge-contract.md`, `docs/specs/cross-source-identity-contract.md` |
| `packages/ingestion/*` | `waaseyaa:ingestion` | `docs/specs/ingestion-defaults.md` |
| `defaults/*`, `bin/check-no-secrets`, `bin/check-ingestion-defaults` | `waaseyaa:security-defaults` | `docs/specs/security-defaults.md` |
| `packages/foundation/src/Diagnostic/*`, `packages/cli/src/Command/Health*`, `packages/cli/src/Command/SchemaCheck*` | `waaseyaa:operator-diagnostics` | `docs/specs/operator-diagnostics.md`, `docs/specs/operations-playbooks.md` |
| `packages/cli/src/CliKernel.php`, `packages/cli/src/CommandDefinition.php`, `packages/cli/src/Parser/**`, `packages/cli/src/Io/**`, `packages/cli/src/Testing/**`, `bin/waaseyaa` | — | `docs/specs/cli-kernel.md` |
| `packages/foundation/src/Http/Inbound/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md` |
| `packages/foundation/*`, `packages/cache/*`, `packages/database-legacy/*`, `packages/plugin/*`, `packages/i18n/*`, `packages/queue/*`, `packages/state/*`, `packages/validation/*`, `packages/typed-data/*`, `packages/testing/*`, `packages/http-client/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md`, `docs/specs/package-discovery.md`, `docs/specs/plugin-extension-points.md`, `docs/specs/external-extension-sdk.md`, `docs/specs/extension-compatibility-matrix.md`, `docs/specs/version-provenance.md`, `docs/specs/extension-release-playbook.md`, `docs/specs/extension-author-onboarding.md` |
| `packages/mcp/*` | `waaseyaa:mcp-endpoint` | `docs/specs/mcp-endpoint.md` |
| `public/index.php` | `waaseyaa:middleware-pipeline` | `docs/specs/http-entry-point.md` |
| `packages/*/src/Middleware/*` | `waaseyaa:middleware-pipeline` | `docs/specs/middleware-pipeline.md` |
| `packages/media/*`, `packages/media/src/Version/*` | — | `docs/specs/entity-storage-two-axis.md` (cross-ref: DIR-005 versioned blob) |
| `packages/note/*` | — | `docs/specs/ingestion-defaults.md` |
| `packages/relationship/*` | — | `docs/specs/relationship-modeling.md`, `docs/specs/relationship-inference-contract.md` |
| `packages/genealogy/*` | — (distribution-extension) | `docs/specs/genealogy.md`, `docs/specs/relationship-modeling.md` |
| `packages/graphql/*` | — | `packages/graphql/README.md` |
| `packages/search/*` | — | `packages/search/README.md` |
| `packages/seo/*` | — | `docs/specs/seo.md` |
| `packages/ssr/*` | — | `packages/ssr/README.md` |
| `packages/error-handler/*` | — | `docs/specs/debugging-dx.md` |
| `packages/debug/*` | — | `docs/specs/debugging-dx.md` |
| `packages/workflows/*` | — | `docs/specs/content-workflow.md` (CW-v1 engine — read first), `packages/workflows/README.md` |
| `packages/billing/*` | — | `packages/billing/README.md` |
| `packages/github/*` | — | `packages/github/README.md` |
| `packages/deployer/*` | — | `packages/deployer/README.md` |
| `packages/frankenphp/*` (optional FrankenPHP dev runtime: `frankenphp:install` + `dev` commands; binary resolver/installer) | — | `packages/frankenphp/README.md`, `docs/specs/operations-playbooks.md` |
| `packages/inertia/*` | — | `packages/inertia/README.md` |
| `packages/engagement/*` | — | `packages/engagement/README.md` |
| `packages/geo/*` | — | `packages/geo/README.md` |
| `packages/mercure/*` | — | `packages/mercure/README.md` |
| `packages/messaging/*` | `waaseyaa:messaging` | `docs/specs/messaging.md` |
| `packages/oauth-provider/*` | — | `packages/oauth-provider/README.md` |
| `packages/analytics/*` | — | `packages/analytics/README.md` (Umami proxy; L0, no waaseyaa deps) |
| `packages/audit/*` | `waaseyaa:ocap-audit` | `docs/specs/ocap-audit-log.md` |
| `packages/mail/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md` |
| `packages/scheduler/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md` |
| `packages/notification/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md` |
| `packages/migration/*` | — | `docs/specs/migration-platform.md` |
| `packages/listing/*` | — | `docs/specs/listing-pipeline-v1.md`, `docs/conventions/cache-tags-and-contexts.md`, `docs/cookbook/listing-first-cut.md` |
| `packages/page-builder/*` | — | `docs/specs/page-builder.md` |
| `packages/site-contract/*` | — | `docs/specs/site-golden-path.md` |
| `packages/publishing/*` (agent-operable editorial CRUD: ContentPublisher, idempotency, signed preview links) | — | `docs/specs/content-publishing.md` |
| `packages/cms/*`, `packages/core/*`, `packages/full/*` | — (consumer metapackages) | `docs/roadmap/packagist-publishing-plan.md`, `docs/adr/004-framework-package-collapse.md`, `tests/PackagedForm/README.md` |

| Workflow, GitHub (PRs/issues), roadmap | — | `docs/specs/workflow.md`, `docs/specs/per-site-convergence-audit.md`, `docs/specs/v1.5-verification-gate-contract.md` |
| `bin/build-phpunit-shards`, `bin/test-random-order`, `bin/verify-random-order-vendor-archive`, `bin/lib/phpunit-inventory.php`, `.github/workflows/nightly.yml`, the `prepare-test-plan`/`prepare-random-order-plan`/`ci-random-order-shard`/`ci-random-order` jobs in `.github/workflows/ci.yml` (package-safe timing-balanced sharding, random-order proof, single run-scoped dependency artifact — changed-package selection was investigated and removed, see the spec's "Rejected design" section) | — | `docs/specs/ci-test-selection.md`, `docs/specs/governed-gates.md` |
| `skills/waaseyaa/app-development/*` | — | — |
| `skills/waaseyaa/framework-extraction/*` | — | `docs/specs/extraction-log.md` |
| `docs/audits/*` | — | — |
| `docs/specs/**`, `.claude/**`, `**/CLAUDE.md` | `waaseyaa:spec-maintenance` | — |

When the mapping is not obvious, search under `docs/specs/` (e.g. `rg -n "TopicOrSymbol" docs/specs/`) or load `skills/waaseyaa/spec-maintenance/SKILL.md`.

## Layer Architecture

| Layer | Name | Packages |
|---|---|---|
| 0 | Foundation | analytics, cache, database-legacy, error-handler, foundation, geo, http-client, i18n, ingestion, mail, mercure, oauth-provider, plugin, queue, scheduler, site-contract, state, typed-data, validation |
| 1 | Core Data | entity, entity-storage, access, audit, user, config, field, auth, oidc, testing |
| 2 | Content Types | node, taxonomy, media, path, menu, note, relationship, groups, engagement |
| 3 | Services | workflows, search, seo, notification, billing, github, migration, listing, page-builder, messaging, publishing |
| 4 | API | api, bimaaji, routing, wayfinding |
| 5 | AI | ai-agent, ai-observability, ai-pipeline, ai-schema, ai-tools, ai-vector |
| 6 | Interfaces | cli, frankenphp, admin-surface, genealogy, graphql, mcp, ssr, telescope, deployer, inertia, debug, workspace |

**Rule:** Packages can only import from their own layer or lower. *Behavioural* coupling across layers is decoupled via the Symfony event dispatcher: a higher layer dispatches a lifecycle event and a lower layer subscribes by string event name, so the higher layer never imports the lower-layer listener. Note this does **not** eliminate upward *type* imports — a lower-layer listener still `use`s the higher-layer event type it subscribes to (e.g. L0 `cache` listeners import L1 `Waaseyaa\Entity\Event\EntityEvent` / `EntityEvents`). Those upward type imports are permitted only via the explicit `KERNEL_EXEMPT_FILES` allowlist in `bin/check-package-layers` (each entry carries a one-line rationale). The framework-wide `DomainEvent` base (`packages/foundation/src/Event/DomainEvent.php`) is a serialization/audit envelope with two concrete subclasses (`EntitySaved`, `EntityDeleted`); it is **not** the type that lifecycle listeners consume — the live `EntityEvent`/`TranslationEvent` lifecycle events extend Symfony `Event` directly.

**Enforcement:** `bin/check-package-layers` enforces the rule on **two** surfaces: (1) every `packages/*/composer.json` runtime `require` edge `waaseyaa/*` against this table (metapackages `cms`, `core`, `full` skipped; runtime `require` only — `require-dev` may pull test fixtures from higher layers, see `bin/audit-require-dev-layers`); and (2) every PHP file-level `use Waaseyaa\…` import under `<pkg>/src/` (rule PL005), which is what catches upward imports that `composer.json` `suggest` (not `require`) would otherwise hide. File-level upward imports are allowed only when the file sits under `<pkg>/src/Kernel/` or is named in `KERNEL_EXEMPT_FILES` with a rationale — this is how the `cache` event-invalidation listeners legitimately import L1 entity/config event types. Historical GitHub **#315** (foundation → path) and **#316** (validation → entity) are closed at the manifest level; re-run scripts after editing internal dependencies or adding a cross-layer listener.

**Exemption:** The `Kernel/` classes in Foundation (`AbstractKernel`, `HttpKernel`, `ConsoleKernel`) are application bootstrappers that wire all layers together. They intentionally import from all layers. This is acceptable because kernels are entry-point orchestrators, not reusable library code — no other package imports from them. The foundation `Http/Router/` built-in domain routers are the analogous **HTTP-substrate** exemption: they implement the L0-owned `DomainRouterInterface`, are wired only by `HttpKernel` (eagerly, so the JSON:API/SSE chain exists on a `core`-only install), and have no external importers — they are a permanent fixture, not pending relocation. See `bin/check-package-layers` `KERNEL_EXEMPT_FILES` and `docs/specs/infrastructure.md` "Kernel exemption surface".

**Auth and OIDC HTTP routes:** Route registration (RouteBuilder / WaaseyaaRouter) for `waaseyaa/auth` and `waaseyaa/oidc` is implemented in `Waaseyaa\Routing\AuthOidcRouteServiceProvider` ([packages/routing](packages/routing)) so L1 auth/oidc packages do not `use` Layer 4 routing types. Service bindings stay in their respective L1 `ServiceProvider` classes; only route wiring is lifted to L4.

## Distribution Extensions

Distribution-extension packages live in `packages/` and split-mirror to Packagist
on the same release cadence as the framework, but they are **not** part of the
framework substrate. Consumers (Nation distributions, civic-tech apps) opt into
them by name. They are not required by `core`, `cms`, or `full`. The
framework-vs-distribution boundary is codified in charter directive DIR-004.

| Package | Purpose | Distribution channel | Spec |
|---|---|---|---|
| `genealogy` | Indigenous family lineage modelling — `genealogy_person`, `genealogy_family`, `genealogy_event`, `genealogy_tree`, lineage / spouse / membership / identity relationship bundles, OCAP-aligned access policies, public SSR pedigree views | Packagist `waaseyaa/genealogy` (split-mirror) | [docs/specs/genealogy.md](docs/specs/genealogy.md) |

## Operation Checklists

**Adding an entity type:**
1. Define `EntityType` with id, label, entity keys, entity class
2. Create entity class extending `EntityBase` — constructor takes `(array $values)`, hardcodes `entityTypeId` and `entityKeys`
3. Register in `EntityTypeManager` via service provider's `register()` method
4. Create storage schema via `SqlSchemaHandler` — define columns, `_data` blob is automatic
5. Add `AccessPolicyInterface` (+ `FieldAccessPolicyInterface` if field-level control needed)
6. Add API routes in `RouteBuilder`, wire controller, set route access options (`_gate` for entity access)
7. Test: use `InMemoryEntityStorage` or `DBALDatabase::createSqlite()` for in-memory testing

**Adding an access policy:**
1. Create class implementing `AccessPolicyInterface` (add `FieldAccessPolicyInterface` if field access needed — same class, intersection type)
2. Register via `#[PolicyAttribute(entityType: 'entity_type_id')]` attribute on the class
3. Implement `access()` returning `AccessResult` — use `::allowed()`, `::neutral()`, `::forbidden()`
4. For field access: implement `fieldAccess()` — Neutral = accessible (open-by-default), only Forbidden restricts
5. Test with anonymous classes implementing both interfaces (PHPUnit `createMock()` can't mock intersection types)
6. Run `waaseyaa optimize:manifest` (or restart dev server) to pick up the new policy

**Adding an API endpoint:**
1. Add route in `RouteBuilder` with access options (`_public`, `_authenticated`, `_session`, `_permission`, `_role`, or `_gate`)
2. Implement controller method following `JsonApiController` CRUD patterns
3. Wire access via route options — `AccessChecker` evaluates them from the matched route
4. For entity endpoints: use `ResourceSerializer` with paired nullable `?EntityAccessHandler` + `?AccountInterface`
5. Add to `SchemaPresenter` if JSON Schema output is needed — set `x-access-restricted` for view-only fields

**Adding middleware:**
1. Implement `HttpMiddlewareInterface` (or `JobMiddlewareInterface`)
2. Add `#[AsMiddleware(priority: N)]` attribute — higher priority runs first (outer onion layer)
3. Middleware is auto-discovered by `PackageManifestCompiler` via attribute scanning
4. Follow handler naming: `{Type}HandlerInterface` for handler, `{Type}MiddlewareInterface` for middleware

**Adding a service provider:**
1. Create class extending `ServiceProvider` in the package's root namespace
2. `register()` — bind interfaces to implementations, register entity types, set up factories
3. `boot()` — subscribe to events, register routes, warm caches (after all providers registered)
4. Add `extra.waaseyaa.providers` to the package's `composer.json` for auto-discovery

**Adding a schedule-entries class:**
1. Create class implementing `ScheduleEntriesInterface` in `packages/<name>/src/Schedule/`
2. Mark with `@api` in PHPDoc (required — dead-code detector gates on this)
3. Declare a `register(ScheduleInterface $schedule): array` method returning `array<string, ScheduledTask>`
4. Ensure constructor dependencies are container-resolvable (type-hint to interfaces bound by service providers)
5. Run `bin/waaseyaa optimize:manifest` (or restart dev server) to trigger discovery
6. Verify with `bin/waaseyaa schedule:list` — your tasks should appear grouped under the class FQCN
7. To disable a built-in entry: add its FQCN to `schedule.disabled_entries` in configuration

**Adding a Bimaaji graph section provider:**
1. Implement `Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface` — `getKey(): string` returns the section identifier; `provide(): GraphSection` returns the versioned payload.
2. Mark with `@api` in PHPDoc if the class is part of a public extension surface (dead-code detector gates on this).
3. Bind it in your package's `ServiceProvider::register()`. The canonical tag is `BimaajiServiceProvider::SECTION_PROVIDER_TAG` (`bimaaji.section_provider`) — use it for forward-compatibility with future tagged-collection container support.
4. Constructor dependencies must be container-resolvable; lean on the kernel-services bus when crossing package boundaries.
5. Verify by resolving `Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator` from the container — the framework's default `BimaajiServiceProvider` only composes the six built-in providers, so a downstream provider currently needs to either (a) rebind `ApplicationGraphGenerator` with its own iterable that includes the new provider, or (b) wait for the tagged-collection resolution feature scheduled for M3's container work.
6. Read `docs/specs/bimaaji.md` "Implementation Status" before adding mutation-side behavior — the validated mutation pipeline (`MutationValidator` → `PatchSet`) belongs to bimaaji's mutation surface, not graph providers.

## Workflow (anchor-issue + design-first)

Substantive work follows the **design-first flow** — brainstorm → spec in `docs/specs/` → written plan → TDD implementation → code review → verification — anchored by a stable, repository-portable change record for multi-candidate efforts. GitHub is the current review/CI/release adapter, never the authority. Full rules: `docs/specs/workflow.md`. Spec Kitty is retired (2026-07-06); do not run `spec-kitty` commands — historical mission artifacts live read-only under `kitty-specs/`, and the charter is at `docs/governance/charter.md`.

**The 4 rules (summary — see `docs/specs/workflow.md` for nuance):**

1. **Substantive work begins with a design and stable change record** — spec first, plan, then TDD; multi-candidate efforts record scope and decisions in versioned repository evidence.
2. **Forge issues are lightweight mirrors** — not every change needs one, and no issue number is a durable identity or audit authority.
3. **Review candidates must be traceable** — bind the portable change-record ID, exact parent/candidate commits, and verification evidence. A forge adapter may add its native links.
4. **Session context** — read the change record, retained decision trail, relevant specs, and any available forge mirror before generating work.

### Commit & PR hygiene (imperative)

- Add a validated `changes/unreleased/<issue>.<slice>.<type>.md` fragment; the
  governed release cut alone compiles root `CHANGELOG.md`.
- After design review, carry a `spec-reviewed:` trailer on the commit (lowercase — the drift detector parses that exact key).
- Never `git stash`. Commit to a branch instead.
- `composer test` must pass before any commit.
- Open PRs via `gh`; require CI green on the exact pushed head before merge.

### Project hooks

- Run `composer hooks:install` once per clone and `composer hooks:doctor` when hook behavior is suspect. The tracked `bin/project-hooks` script is the source of truth; installed Git hooks are worktree-aware shims only.
- Pre-push runs `bin/check-pr-preflight` — every fast repo-state gate CI blocks on, all blocking (spec drift included; no advisory splits). `php bin/check-pr-preflight --full` (adds phpstan + dead-code) plus the three test suites are the complete publication gates; `bin/refresh-governance-artifacts` repairs stale recorded rosters/baselines. Gate roster: `tools/preflight-gates.json`; contract: `docs/specs/governed-gates.md`.
- Claude startup receives only bounded branch and working-tree context. Run `tools/drift-detector.sh origin/main` explicitly when reviewing specification impact; do not inject full drift reports into session context.

## Agent context

- **Constitution (this file):** Session-hot rules — orchestration table, layer graph, checklists, gotchas.
- **Specialist skills:** `skills/waaseyaa/*` — load on demand for a subsystem; each skill lists related specs.
- **Cold specs:** `docs/specs/*.md` — read directly from disk when you need contracts, file maps, and edge cases (no spec MCP server).

**Workflow precedence:** **Anchor issues** own effort scope and work-package sequencing. **GitHub** owns merge mechanics, CI, releases, and issues. **`docs/specs/`** owns subsystem contracts — read from disk, update when behaviour changes.

Design docs in `docs/history/plans/` are session artifacts (implementation history). Specs in `docs/specs/` are enduring architectural knowledge (kept current). When refactoring a subsystem, update its spec — run `tools/drift-detector.sh` to find stale specs.

## Commands

**Testing** (do NOT use `-v` flag, PHPUnit 10.5 rejects it). There are **three** suites — Unit, Integration, AND Architecture; CI runs all three, so "tests green" locally means all three, not the first two:
- `./vendor/bin/phpunit` — run all tests
- `./vendor/bin/phpunit --testsuite Unit --no-coverage` — unit tests only
- `./vendor/bin/phpunit --testsuite Integration --no-coverage` — integration tests only
- `./vendor/bin/phpunit --testsuite Architecture --no-coverage` — repo-state contract tests (S1 rosters, CI shape, hooks, governance) — CI runs this inside ci/unit-tests; forgetting it locally was the #2399 five-red-jobs surprise
- `./vendor/bin/phpunit --filter Phase10` — run tests matching a pattern
- `./vendor/bin/phpunit packages/mail/tests/` — run a single package's tests

**Platform — the suite is Linux-first; run it split, not as one process:**
- Run the **split suites** (`--testsuite Unit --no-coverage`, then `--testsuite Integration --no-coverage`, then `--testsuite Architecture --no-coverage`), not a bare `./vendor/bin/phpunit`: the whole suite as a single process OOMs at PHP's default 128 MB `memory_limit`. Raise `memory_limit` or run the suites separately (CI runs them as separate jobs on Linux). The explicit `--no-coverage` is required when no coverage driver is installed; otherwise the configured coverage report emits a runner warning before discovery and zero tests execute.
- **Windows contributors:** the CLI snapshot tests pass on Windows because their fixtures (`*.stdout` / `*.stderr` / `*.exit`) are pinned to `eol=lf` in `.gitattributes` — with `core.autocrlf=true` they were otherwise checked out CRLF and ~72 `CliTester` snapshot assertions failed on the line endings alone. The *remaining* Windows failures are **POSIX-only by design** and are expected to fail off Linux: the release-tooling tests assume `bash`, `proc_open`, POSIX advisory file locks, and symlinks; the bin-script and OIDC-RSA tests assume a POSIX toolchain. `composer test` / `composer verify` are green on Linux and in CI. Treat a Windows-only failure in those areas as environmental — confirm it in a separate clean Linux worktree before assuming you introduced it.

**Code quality:**
- `php bin/check-pr-preflight` — run every fast repo-state gate CI blocks on (~10s; `--full` adds phpstan + dead-code; `--list` prints the roster). Run this before claiming gates green — see `docs/specs/governed-gates.md`
- `php bin/check-phpunit-skip-policy` — report the complete governed skip inventory and reject new/unclassified skips, broad exception-to-skip conversion, or skips in required-hosted transport proofs. See `docs/specs/phpunit-skip-governance.md`
- `php bin/refresh-governance-artifacts` — repair stale recorded rosters/baselines (auto-regenerates mechanical ones, prints instructions for judgment ones)
- `composer cs-check` — check code style (dry-run PHP-CS-Fixer)
- `composer cs-fix` — auto-fix code style
- `composer phpstan` — static analysis (PHPStan 2.x, level 5)
- `composer check-composer-policy` — enforce codified Composer manifest policy
- `bin/check-package-layers` — enforce internal `waaseyaa/*` dependency layers (see Layer Architecture)
- `bin/audit-require-dev-layers` — warn-only report for upward `require-dev` `waaseyaa/*` edges

**Development:**
- `composer dev` — start dev server (PHP built-in server + admin SPA)
- `bin/waaseyaa` — CLI entry point (SQLite + file config)
- `bin/waaseyaa optimize:manifest` — rebuild attribute-discovery manifest

## Code Style
- PHP 8.5+, `declare(strict_types=1)` in every file
- Namespace pattern: `Waaseyaa\PackageName\` (e.g., `Waaseyaa\Entity\`, `Waaseyaa\AI\Schema\`)
- Test namespace: `Waaseyaa\PackageName\Tests\Unit\` or `Waaseyaa\Tests\Integration\PhaseN\`
- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(...)]`, `#[CoversNothing]` for integration tests
- Symfony 7.x components (EventDispatcher, Routing, Validator, Uid, Yaml, Messenger, **Console**). NOTE: the CLI runtime is built on **Symfony Console** as of commit `614d88f47` — `Waaseyaa\CLI\WaaseyaaConsoleApplication` extends Symfony `Application` and `Waaseyaa\CLI\Command\HandlerCommand` extends Symfony `Command`, wired by `ConsoleApplicationFactory`. (The earlier hand-rolled native kernel — `CliApplication`/`CliKernel`/`CommandRegistry` — was removed in that migration.)
- Named constructor parameters: `new EntityType(id: 'node', label: 'Content', ...)`
- `final class` by default for concrete implementations
- Admin SPA: Nuxt 3 + Vue 3 + TypeScript. Composables in `packages/admin/app/composables/`, i18n in `packages/admin/app/i18n/en.json`
- Brand color: Deep Teal (`#0d4f4f` → `#0f766e` → `#14b8a6`). Chosen to be distinct from Drupal (blue), Laravel (red), Django/Nuxt (green), Strapi (purple). Auth CSS tokens and AdminShell `--color-primary` use this palette.
- Frontend entry point: `public/index.php` (PHP built-in server front controller)

## Dead code audits and intentional scaffolding

We run `shipmonk/dead-code-detector` via `phpstan-dead-code.neon` as a **hard CI gate** (`bin/check-dead-code`, also in `composer verify`). Pre-existing findings are suppressed by `phpstan-dead-code-baseline.neon`; any *new* dead-code finding fails CI. Phase 4 of the dead-code cleanup audit (`docs/audits/2026-05-17-dead-code-baseline-audit.md`) flipped this from warn-only to fail-on-new in PR #1504 after the baseline dropped from 1,341 → 66 entries.

Reflection-discovered entrypoints — auto-marked as used by `tools/phpstan/WaaseyaaEntrypointProvider.php`:
- Classes carrying `#[PolicyAttribute]` or `#[AsMiddleware]`.
- FQCNs declared in `extra.waaseyaa.providers`.
- Classes whose FQCN sits under a `\Ingestion\EntityMapper\` namespace segment.
- Implementors of `RouteProviderInterface`.
- Subclasses of `EntityBase` / `ContentEntityBase`, plus the traits they `use` (members hydrated via `ReflectionProperty::setValue` and `ContentEntityBase::set()` are call-graph-invisible).
- Classes carrying class-level `@api` PHPDoc (the canonical signal — covers extension points, public service facades, DTOs, the entire `packages/testing/src/` consumer surface).

If you add a new discovery convention, extend that provider before relying on the gate.

### Marking intentional scaffolding

If you add code that is not yet referenced but is part of a planned extension point or feature, mark it with `@api` in PHPDoc. shipmonk's `ApiPhpDocUsageProvider` (enabled by default via `vendor/shipmonk/dead-code-detector/rules.neon`) treats `@api` as a "used by design" signal and will not report it as unused.

Use `@api` for:
- Public extension points (interfaces, abstract classes, traits) intended for third-party or cross-package use.
- Attribute classes and entity types discovered via reflection or configuration.
- Forthcoming feature stubs expected to be wired up in a later PR.

```php
/**
 * @api
 */
final class SomeFutureExtensionPoint
{
    // ...
}
```

Do **not** use `@api` for internal helpers, temporary spikes, or anything you would be comfortable deleting if it stayed unused. When in doubt, leave it off — unused internal code should be deleted once it is clearly not needed.

### Triage rule for findings

When acting on dead-code findings (separate from this CI gate):
- **Public extension points / attributes / entities / reflection-discovered types / clearly-named forthcoming stubs** → either add `@api` and keep, or move into a feature branch until wired.
- **Private/internal helpers, unused methods/properties/constants with no callers** → safe candidates for deletion or refactor.
- For automated passes: only propose deletions for private/internal symbols with no `@api` and no references; leave anything public or reflective-looking alone unless explicitly approved.

To regenerate the baseline after a triage sweep: `vendor/bin/phpstan analyse -c phpstan-dead-code.neon --generate-baseline=phpstan-dead-code-baseline.neon`. To inspect the historical backlog without running CI, grep `phpstan-dead-code-baseline.neon`.

## CI Gates

Two fail-on-new gates run as part of `composer verify`.

### Dead code gate

`bin/check-dead-code` + `phpstan-dead-code-baseline.neon` — fails on new unreferenced symbols. See the "Dead code audits" section above.

### Unbound getQuery() gate

`bin/check-getquery-bindings` — fails on new `getQuery()->...->execute()` callsites that have neither `->setAccount()` nor `->accessCheck(false)` in the call chain. Introduced in PR #1528 after three production 500s from unbound chains (alpha.181–triage 2026-05-20).

**Baseline file**: `tools/getquery-bindings-baseline.txt`

**Adding a new exemption**: Append `packages/foo/src/Bar.php:<line>  # <reason>` to the baseline and commit it in the same PR. Every entry **must** have an inline comment explaining why the callsite is exempt. Entries without a comment cause CI to fail with "Incomplete baseline entries".

**Regenerating the baseline** (after fixing a batch of callsites or after a rename):
```bash
php bin/check-getquery-bindings --generate-baseline
```
Review the output, replace all `# TODO: add exemption reason` stubs with real reasons, and commit. The M-B.1 follow-up issue tracks driving this baseline to zero.

**What counts as "bound"**:
- `$storage->getQuery()->setAccount($account)->...->execute()` — bound, OK.
- `$storage->getQuery()->accessCheck(false)->...->execute()` — explicit opt-out, OK (add an inline justification comment in the production source file).
- `$storage->getQuery()->...->execute()` with no binding — NEW offender, CI fails.

## Architecture Gotchas

Cross-cutting rules that affect work anywhere in the framework. Subsystem-specific gotchas live in their respective `docs/specs/*.md` files (see Orchestration table).

**Entity & storage:**
- **Entity subclass constructors**: User, Node etc. only accept `(array $values)` and hardcode entityTypeId/entityKeys. SqlEntityStorage uses reflection to detect constructor shape.
- **`enforceIsNew()` for pre-set IDs**: When creating entities with pre-set IDs (e.g., `new User(['uid' => 2])`), call `$entity->enforceIsNew()` before `save()`. Otherwise `isNew()` returns false, storage tries UPDATE instead of INSERT, and silently affects 0 rows.
- **Dual-state bug pattern**: When data can come from two sources (e.g., attribute vs registry), always use one canonical source. Found repeatedly in ComponentRenderer, Pipeline, entity values.
- More entity gotchas (`_data` JSON blob, `EntityEvent` public props, etc.) live in [docs/specs/entity-system.md](docs/specs/entity-system.md) §"Implementation gotchas".

**Database / DBAL:**
- **`DatabaseInterface` vs `DBALDatabase`**: `DatabaseInterface` does NOT have `getConnection()`. If the DBAL `Connection` is needed, type-hint `DBALDatabase` directly. Prefer the query builder (`select()`, `insert()`, `delete()`) over raw DBAL when possible.
- **DBAL quirks**: `fetchAssociative()` returns associative arrays (equivalent to `FETCH_ASSOC`); empty `IN`/`NOT IN` (`condition('id', [], 'IN')`) silently returns no results — guard with empty check; for LIKE/NOT LIKE the caller passes a complete pattern and owns wildcards — escape literal `%`/`_` in untrusted input with `str_replace(['%', '_'], ['\\%', '\\_'], $value)`. This is by design, not a footgun: `DBALSelect::condition()` binds the value as a parameter and appends `ESCAPE '\'` precisely so that backslash-escaping works; it intentionally does NOT auto-escape `$value` (that would forbid wildcards and double-escape callers like `SqlColumnQueryTranslator` CONTAINS/STARTS_WITH).
- **`database-legacy` namespace is `Waaseyaa\Database`**: Despite the directory being `packages/database-legacy/`, the PHP namespace is `Waaseyaa\Database`, NOT `Waaseyaa\DatabaseLegacy`. Check `composer.json` autoload for the canonical namespace. See `docs/adr/007-database-legacy-package-naming.md`.

**Layers, packages, namespaces:**
- **Layer discipline for imports**: Foundation (layer 0) must never import from higher layers. When cross-layer attribute scanning is needed, use string constants instead of `::class` references (e.g., `private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute'`). `ReflectionClass::getAttributes()` accepts string class names.
- **Avoid circular package deps**: Access owns `AccountInterface`; User owns `AnonymousUser`. Access must not depend on User. Middleware needing an account should type-hint `AccountInterface`, not concrete `AnonymousUser`. Five historical **same-layer** 2-cycles are accepted-but-bounded in `tools/package-layers-cycle-baseline.txt`: `access` ↔ `entity`, `ai-agent` ↔ `ai-observability`, `cache` ↔ `foundation`, `entity` ↔ `field`, and `foundation` ↔ `queue`. `bin/check-package-layers` emits those exact pairs as **`WARN [PL006] ... Accepted baseline`**; any new same-layer cycle is a hard `PL006` failure.
- **Never put classes that extend dev-only deps under `autoload`**: Any class under `src/` is reachable via PSR-4 in production consumer installs (`composer install --no-dev`). If such a class `extends PHPUnit\Framework\TestCase` or any dev-only symbol, a consumer's `PackageManifestCompiler` class scan will Reflection-load it, fail to resolve the parent, and crash kernel boot with "Application failed to boot." Fix: put test-helper base classes in a top-level `testing/` directory (sibling to `src/` and `tests/`) and register `"Waaseyaa\\Foo\\Testing\\": "testing/"` under `autoload-dev` only. Caught in `waaseyaa/graphql` alpha.106 → alpha.107 via a production outage on minoo.

**HTTP, auth, request lifecycle:**
- **Request attribute is `_account` not `account`**: SessionMiddleware sets `$request->attributes->set('_account', $account)`. Any code reading the authenticated account (controllers, surface hosts, middleware) must use `_account`. Reading `account` (no underscore) silently returns `null`.
- **`php://input` is single-read**: `HttpRequest::createFromGlobals()` consumes `php://input`. For subsequent body reads, use `$httpRequest->getContent()`, not `file_get_contents('php://input')`.
- **Account sentinel IDs**: `AnonymousUser` uses `id: 0`, `DevAdminAccount` uses `PHP_INT_MAX`. Never use `1` or other low integers for non-real accounts — they collide with auto-increment UIDs.

**Logging, side effects, file I/O:**
- **No `psr/log`**: Use `Waaseyaa\Foundation\Log\LoggerInterface`. Accept `?LoggerInterface $logger = null` in constructors and default to `NullLogger`. Reserve `error_log()` only for last-resort fallbacks inside the logging infrastructure itself.
- **Best-effort side effects**: Event listeners for non-critical operations (broadcasting, logging, cache invalidation) should wrap in try-catch and log via `LoggerInterface` to avoid crashing the primary request.
- **JSON symmetry**: Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent `null` on corrupt data.
- **Atomic file writes**: Cache files must use write-to-temp-then-rename (`file_put_contents($tmp)` then `rename($tmp, $target)`) to prevent serving partial writes.

**PHP version / language:**
- **PHP 8.4 parameter defaults can't call static methods**: `SomeClass::create()` is not valid as a constructor parameter default. Use nullable + resolve in body: `?Foo $foo = null` then `$this->foo = $foo ?? Foo::create()`. Audit all callers when changing constructor defaults — replacing a self-contained default (`new EditorialWorkflowStateMachine()`, pre-populated) with an empty generic (`new Workflow()`, zero states) silently breaks every consumer relying on the default.
- **PascalCase conversion**: Use `str_replace('_', '', ucwords($name, '_'))`, not `ucfirst()`.

**Testing:**
- **PHPUnit `createMock()` limitations**: Fails on `final class` — use real instances with temp directories (`sys_get_temp_dir() . '/waaseyaa_test_' . uniqid()`). Fails on intersection types (e.g. `AccessPolicyInterface & FieldAccessPolicyInterface`) — use anonymous classes implementing both interfaces. `createMock()` + `willReturn(null)` on void methods throws `IncompatibleReturnValueException` — use `createStub()` or omit `willReturn()`.

**Meta:**
- **Stale specs cause bad code**: When refactoring a subsystem, update the relevant `docs/specs/` file. Stale specs cause agents to generate code conflicting with recent changes. Run `tools/drift-detector.sh` to find affected specs.

## Testing
- Integration tests in `tests/Integration/PhaseN/` — one directory per implementation phase
- GraphQL integration tests in `tests/Integration/GraphQL/` — full-stack tests with real SQLite via `DBALDatabase::createSqlite()`
- Unit tests in `packages/*/tests/Unit/`
- Use `CliTester` (`Waaseyaa\CLI\Testing\CliTester`, in `packages/cli/tests/Testing/`) for CLI command tests — `CliTester::for($definition, $container)->execute([...])`, then assert `getExitCode()` / `getStdout()`. `CliTester` wraps Symfony Console's `CommandTester` for you (the CLI now runs on Symfony Console), binding the container via `HandlerCommand::withContainer()` — prefer it over instantiating `CommandTester` directly.
- Use `ArrayLoader` for Twig tests (no filesystem needed)
- All storage can be in-memory: MemoryStorage (config), MemoryBackend (cache), InMemoryEntityStorage (entities), DBALDatabase::createSqlite() (SQL with :memory:)
- Test PHP-file cache recovery (the manifest cache in `PackageManifestCompiler`, config file caches) with corrupt files (`<?php throw new \RuntimeException("corrupt");`) and wrong return types (`<?php return "not an array";`) to verify recovery paths. NOTE: the `waaseyaa/cache` `DatabaseBackend` stores serialized BLOBs, not PHP files (there is no `FilesystemBackend`); its corrupt/tampered-row recovery is covered separately in `DatabaseBackendTest` (a corrupt row is treated as a miss).
- Contract tests in `packages/*/tests/Contract/` — abstract base classes verify interface compliance, concrete tests per implementation. Use `#[CoversNothing]` for contract tests.
- Test access policies with anonymous classes implementing intersection types (`AccessPolicyInterface & FieldAccessPolicyInterface`) — PHPUnit `createMock()` can't mock intersection types, so use real anonymous classes with inline logic
- Frontend tests: `cd packages/admin && npm test` — Vitest with `@nuxt/test-utils` nuxt environment
- Frontend build verification: `cd packages/admin && npm run build` — TypeScript compilation check
- Frontend E2E: `cd packages/admin && npm run test:e2e` — Playwright specs in `e2e/`; requires `nuxt dev` on port 3000

## Environment
- `APP_ENV` — Application environment: `local`, `dev`, `development`, `testing`, `staging`, `production` (default: `production`)
- `APP_DEBUG` — Debug mode toggle (default: `false`). Enables detailed error pages, debug toolbar, debug headers. **Kernel refuses to boot if `APP_DEBUG=true` in production.**
- `LOG_LEVEL` — Minimum log level for default handler: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` (default: `warning`)
- `WAASEYAA_DB` — SQLite database path (default: `./storage/waaseyaa.sqlite`)
- `WAASEYAA_CONFIG_DIR` — config sync directory (default: `./config/sync`)
- `WAASEYAA_ENTITY_VALIDATION` — save-time entity validation toggle (default: enabled). Values `0`/`false`/`off` (case-insensitive) disable kernel-wired validation; read once at boot.

## Architectural Boundaries

Waaseyaa is the **framework layer**. It owns the entity system, storage engine, field types, ingestion envelope contract, GraphQL/REST API, access control, and SSR rendering.

**Waaseyaa does NOT own:**
- Minoo-specific entity types (those belong in Minoo's src/Entity/)
- Content classification or routing (that's North Cloud)
- Map UX, dialect logic, or community-specific features (that's Minoo)

**Import rules:**
- Waaseyaa must not import from Minoo — the dependency flows one way (Minoo → Waaseyaa)
- Waaseyaa must not reference North Cloud services or APIs
- Waaseyaa defines the ingestion envelope contract that external tools (Python harvesters) must follow
