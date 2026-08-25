# Waaseyaa Stability Charter

<!-- Spec reviewed 2026-07-17 - #2064 WP1 follows §4 by adding the V2 storage SPI and dormant compatibility adapter before the approved alpha no-shim activation. The stable FieldDefinitionInterface and all existing read/serialization/V1 behavior remain unchanged in WP1. Activation owns the breaking-change guide, checklist, release-note heading, V1 removal, guarded toArray(), and serialization break. -->

**Status:** In force (solo-developer project — no ratification ceremony; see §12). Strategic decisions complete (ADRs 010–018 accepted, 012 superseded by 012a); §11 operational questions resolved (2026-05-11); CI enforcement live — `surface-parity` is a real blocking AST gate and the full `composer verify` gate set runs in CI (`ci/verify-gates`).
**Owner:** `@jonesrussell` (sole maintainer and release authority)
**Authoritative on:** API stability, deprecation process, breaking-change procedure
**Defers to:** [`VERSIONING.md`](../VERSIONING.md) for release stages, tag policy, and v1.0 sign-off
**References:** [`public-surface-map.md`](../public-surface-map.md), [`specs/extension-release-playbook.md`](extension-release-playbook.md)

---

## 0. Origin

This charter exists because the 2026-05-11 framework/app audit found that recent alpha trains shipped **good framework decisions** at a cost that none of the consumer apps could plan around:

| Train | Change | Consumer cost |
|---|---|---|
| alpha.106 | Controllers must return `Symfony\Component\HttpFoundation\Response` | Every controller touched |
| alpha.107 | Community-scoped tenancy + `SovereigntyProfile` + `AppControllerRouter` | New attributes across the HTTP surface |
| alpha.173 | `HasCommandsInterface` opt-in marker | Missing marker → commands silently disappear |
| alpha.175 | `symfony/console` hard-cut; `HasCommandsInterface` → `HasNativeCommandsInterface`; Handler/`CommandDefinition` split | Full console layer migration |

`VERSIONING.md` permits breaking changes pre-v1. It does not say *how* to ship one. This charter does.

---

## 1. Purpose & scope

### 1.1 What this charter governs

- Public API stability of the Waaseyaa framework (`waaseyaa/*` packages enumerated in [`public-surface-map.md`](../public-surface-map.md)).
- Breaking-change process across alpha, beta, and stable phases.
- Deprecation lifecycle, including log channels, shim conventions, and removal windows.
- Maintainer obligations when merging breaking changes.
- Consumer obligations when staying on a supported framework sync line.

### 1.2 What this charter does not govern

- Application code in consuming apps (e.g. Minoo). Apps choose their own API stability story.
- Pre-publication branches and experimental packages under `waaseyaa/experimental-*` (if introduced).
- Release tagging and `v1.0` sign-off — those remain authoritative in `VERSIONING.md`.
- Bug fixes that restore documented behavior — those are not breaking changes by definition (see §5.0).

### 1.3 Who this charter binds

- **Framework maintainers** — must follow the breaking-change procedure (§4) and the merge checklist (§8.3) when shipping any change that crosses the public surface.
- **App authors** consuming `waaseyaa/*` packages — must meet the consumer obligations in §6 to receive supported-upgrade treatment.
- **Extension authors** publishing `waaseyaa-ext-*` packages — same obligations as app authors plus the [`extension-release-playbook`](extension-release-playbook.md).

---

## 2. Surface classification

The framework public surface uses a **binary** classification: every tracked element is either **public** or **internal** (audit C-15). An earlier three-tier `stable | provisional | internal` model and a `mission_status` axis — described in §2.1–§2.3 and §2.6 below, and in §11 Q1 — were aspirational and **never shipped**; the public-surface-map, the surface-parity gate (`tools/check-surface-parity.php`), and `PublicSurfaceVerificationTest` all implement the binary model. §2.1–§2.3 are retained only for historical context.

The tracked surface is the **contract shapes** — interfaces, abstract classes, traits, and enums declared under `packages/*/src` — together with the config keys, log channels (§4.4), event names, CLI commands, and env vars enumerated in this charter. Concrete `final`/plain classes are implementations, not extension points, and are intentionally **not** tracked by the parity gate (audit C-16): there are ~1378 of them versus ~426 mapped contract elements, and listing them would not strengthen the stability guarantee — consumers depend on the interface, not the implementation (e.g. `EntityTypeManagerInterface` is mapped `public` while the concrete `EntityTypeManager` is not). The single source of truth for an element's disposition is the public-surface-map (§2.5).

### 2.1 Stable

Symbols apps may depend on with confidence. Stable symbols carry the strongest breakage guarantees in the current release phase (§3).

**Stable by default:**

- Every interface in `Waaseyaa\Foundation\Contracts\*` and equivalent contract namespaces of layer-0/1 packages in the public-surface-map.
- The boot contract: `HttpKernel::handle()`, `ConsoleKernel::handle()`, `public/index.php` shape.
- The routing/controller contract (controller return type, attribute resolution, parameter injection rules).
- The console/command discovery contract (provider capability interfaces, `CommandDefinition`).
- The entity/storage public API: `EntityInterface`, `EntityStorageInterface`, `FieldDefinition`, `EntityType`, the access-policy attribute system.
- The manifest/compiler outputs that consumers can observe (composer `extra.waaseyaa.providers` schema, the compiled `packages.php` shape *as a read interface for ours-only debugging*).
- Config keys documented in any `config/*.php` shipped by the framework.
- Env vars whose names appear in shipped docs (e.g. `WAASEYAA_DB`, `WAASEYAA_LOG_LEVEL`).
- Public log channels declared in this charter (§4.3).

### 2.2 Provisional

Symbols intended to become stable but not yet locked. Apps may use them but must monitor deprecation notices. Provisional symbols may be reshaped between alpha trains under §4 (deprecation required), or replaced wholesale during beta convergence.

**Provisional by default:**

- New public APIs in their first two alpha trains after introduction.
- Anything explicitly marked `@provisional` in PHPDoc.
- Anything documented as "preview" in a release note.

A symbol exits provisional status when (a) it has shipped unchanged across two alpha trains *and* (b) a maintainer reclassifies it in the public-surface-map.

### 2.3 Internal

Symbols not part of the supported surface. Apps depending on them do so at their own risk and forfeit upgrade support for code that breaks on internal changes.

**Internal by default:**

- Anything inside an `Internal\` namespace segment.
- Anything marked `@internal` in PHPDoc.
- Concrete implementation classes when an interface exists for the same role (e.g. `EntityTypeManager` is internal; `EntityTypeManagerInterface` is stable). See §5.3.
- Storage layout (SQLite table shape, `_data` blob layout, manifest cache file format on disk).
- The `storage/framework/packages.php` file format. Consumers must use the manifest-reading API, not the file.
- Anything under `tests/`, `tools/`, or `bin/dev-*`.

### 2.4 Grey zone — disposition rules

A symbol whose tier is unclear is treated as **provisional** until a maintainer files a public-surface-map update. The default during ambiguity favors the consumer: maintainers must either commit to stability or downgrade to internal explicitly. Indefinite ambiguity is a charter violation.

### 2.5 Where classification lives

Single source of truth: [`public-surface-map.md`](../public-surface-map.md) and its companion `public-surface-map.php`. Every tracked contract shape is labeled `public` or `internal` (the binary model — §2 above); the surface-parity gate (`tools/check-surface-parity.php`) fails on any source element missing from the map, so the labeling is **complete by construction** — there are no unlabeled items. Audit C-15 (surface tier labeling) is therefore satisfied and the §3.2-1 beta-entry criterion is met. (The earlier `stable | provisional | internal` scheme never shipped — see §2.)

### 2.6 Mission-status column

Tier (§2.1–2.4) governs *API stability*; it does not measure *mission completeness*. A stable interface is still a stable interface even when the feature it represents covers only part of the use cases consumers expect.

To make this measurable, the public-surface-map gains a second classification axis: **mission status**, derived from [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md):

| Status | Meaning |
|---|---|
| `present` | Feature ships and covers the documented use cases |
| `partial` | Feature ships but is incomplete relative to the matrix's reference scope |
| `planned` | Feature has an accepted ADR and/or open mission; not yet shipping |
| `intentional-gap` | Feature is documented as out of scope; will not be built |

Mission-status labels are advisory to consumers, not part of the breakage contract. A `partial` symbol may still be `stable` — consumers know the contract won't break, *and* know the feature is incomplete. The pairing prevents the "we declared beta but Views doesn't exist" failure mode.

Mission status updates as ADRs accept and missions ship; tier updates follow the deprecation cycle (§4). The two axes are independent.

---

## 3. Versioning & lifecycle

This section operationalizes `VERSIONING.md` §4 ("Compatibility and Schema Rules") with explicit per-phase guarantees. It does not replace `VERSIONING.md`.

### 3.1 Alpha (current phase)

Pre-v1 alpha trains (`0.x-alpha.N`).

**Allowed:**
- Breaking changes to any surface tier, including stable, when justified.
- Removal of provisional symbols.
- Removal of internal symbols at any time, without notice.

**Required for stable-surface breaks** (this is the change from past practice):
- Deprecation cycle per §4, even if abbreviated.
- A shim or compatibility adapter, unless infeasible (must be argued in the merge checklist).
- An upgrade-guide entry per §7.
- Listed in the release's `## Breaking changes` section (§8.2).

**Implication:** alpha does *not* mean "break freely." It means "break with process." The audit's F3 finding was that recent alpha trains skipped the process, not that they shipped breaks.

### 3.2 Beta (future, gated)

Pre-v1 beta trains (`0.x-beta.N`).

**Entry criteria** (all must hold; not date-driven). Waaseyaa is a **single-developer project moving fast**: the beta bar is **code health and enforced CI**, nothing else. There is no owner-approval artifact, no third-party / non-Waaseyaa-org consumer requirement, and no ratification vote — beta is entered when the engineering criteria below are objectively green on `main`. (See §3.4 for the transition mechanism, which is a code-health check, not a sign-off.)

1. **Surface labeling complete.** Every package in the public-surface-map has every exported item tier-labeled. No unlabeled items.
2. **Two clean alpha trains.** Two consecutive alpha trains have shipped without an undeprecated stable-surface break — *enforced by the surface-parity gate (§8.1), not asserted by hand*.
3. **Deprecation budget under threshold.** Active deprecations carrying shim debt do not exceed 10. (Forcing function: if the list grows, the framework must spend a cycle paying it down before entering beta.)
4. **CI enforcement live & green.** The checks in §8 are wired and green on `main`, and `composer verify` — the full gate set — passes on `main`.
5. **No unresolved critical mission gaps.** No `❌` entries in [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) §3 ("Mission-critical gaps") remain in `unknown` or `unresolved` state. `intentional-gap` decisions documented via ADR are acceptable; undecided gaps are not. **Per-field translation (matrix §3.2) — SATISFIED** by M-006 (`entity-storage-translations-v1`) shipping the single-axis translation substrate per ADR 017; see §5.3 for the stable surface. **CMI config sync (matrix §3.5) — SATISFIED** by M-003 (`config-management-v1-01KRCDEC`) shipping the active/sync store split per ADR 018; see §5.5 for the stable surface (`ConfigDependencyInterface`, sync-store YAML format, seven `config:*` commands, `config.audit` log channel, backend restriction).

The stable surfaces for the listing pipeline (§5.6, §5.9) and revisions (ADR 016, `RevisionableEntityInterface`) are already ratified by their missions; beta entry does **not** additionally require a production-consumer demonstration of them.

**Beta rules:**
- Stable-surface breaks require a full deprecation cycle (§4) — no abbreviated path.
- Provisional symbols may still change but must use the deprecation cycle.
- Shim removal windows lengthen (§4.5).
- Each breaking change must be tied to an open issue and an upgrade-guide draft *before* the PR can merge.

### 3.3 Stable (v1.0+)

Governed by `VERSIONING.md` §4 ("Post-v1.0"). This charter binds the framework to:

- Semantic versioning — breaks require a major bump.
- Documented migration path per breaking change.
- No removal of stable symbols within a major version, period.
- Provisional tier dissolves; v1.0 ratifies every surviving symbol as either stable or internal.

### 3.4 Phase transitions

| From | To | Mechanism |
|---|---|---|
| alpha | beta | All §3.2 entry criteria objectively green/enforced on `main` (a code-health check — **no approval artifact, no sign-off PR**) |
| beta | stable (`v1.0`) | Per `VERSIONING.md` §6 — the one-time v1.0 owner sign-off is a deliberate anti-accident gate for the *stable* milestone (enforced by `release-gate.yml`'s `v1.0*` tag quarantine), distinct from the fast-moving alpha/beta cadence above |
| any | hotfix branch | Maintainer prerogative; hotfix branches inherit their parent phase's rules |

---

## 4. Deprecation policy

Any breaking change to a stable symbol — and any deliberate reshape of a provisional one — follows the cycle in this section.

### 4.1 The five steps

1. **Introduce.** Land the new API in its target shape.
2. **Shim.** Make the old API call through to the new one, or wrap it in a compatibility adapter. If a shim is impossible, the merge checklist (§8.3) requires a written argument.
3. **Emit.** Deprecation notices fire on every use of the old surface (§4.3).
4. **Document.** Add an entry to the next upgrade guide (§7) and a `deprecated` changelog fragment.
5. **Remove.** After the removal window (§4.5), delete the shim and the old API in a single PR. Move the deprecation entry to `### Removed`.

### 4.2 Worked example A — the implicit-array dispatcher shim

The post-framework-#1390 dispatcher shim is the canonical example.

- **Step 1 — Introduce:** the new controller-attribute resolution path was added.
- **Step 2 — Shim:** controllers with implicit-array signatures still resolve via the shim.
- **Step 3 — Emit:** notices fire on channel `dispatcher.deprecation`, event `implicit_array_shim`, format `Controller <FQCN> parameter $<name> needs add #[<Attribute>]`. Deduped per `(class::method::parameter)` triple per worker lifetime.
- **Step 4 — Document:** the implicit form uses a `deprecated` changelog fragment and is listed in the upgrade guide for the alpha that introduced the shim.
- **Step 5 — Remove:** scheduled after Minoo's implicit-array backlog (audit finding F4/M9) is empty *and* the removal window has elapsed.

This is how every future deprecation should look.

### 4.3 Worked example B — `HasCommandsInterface` (anti-example)

The alpha.173 introduction of `HasCommandsInterface` as an opt-in marker, where providers without the marker silently dropped their commands, is the canonical *anti-example*.

What went wrong:
- No shim. Providers that previously returned commands had them silently ignored.
- No deprecation notice. The failure mode emitted nothing.
- No upgrade guide. App authors discovered the change when commands stopped working.

What this charter requires for the equivalent change going forward:
- Step 1 — Introduce `HasCommandsInterface`.
- Step 2 — Continue dispatching commands from providers without the marker via a shim path.
- Step 3 — Emit a `notice` on channel `console.deprecation` for each unmarked provider: `Provider <FQCN> contributes commands but does not implement HasCommandsInterface; add the marker before <removal alpha train>`.
- Step 4 — Upgrade guide entry listing every framework-shipped provider already updated, with a one-line snippet for app authors.
- Step 5 — After the removal window, drop the shim. Unmarked providers' commands are then ignored — but no one is surprised.

The same template applies to the alpha.175 `HasCommandsInterface` → `HasNativeCommandsInterface` rename.

### 4.4 Log channels (normative)

Deprecation notices use the structured log layer. Channels are part of the stable surface (§2.1).

| Channel | Scope |
|---|---|
| `dispatcher.deprecation` | Controller dispatcher / attribute resolution |
| `console.deprecation` | Console kernel / command discovery |
| `entity.deprecation` | Entity system / storage |
| `routing.deprecation` | Router / route definitions |
| `config.deprecation` | Config keys, env vars |
| `boot.deprecation` | Kernel boot / provider registration |
| `framework.deprecation` | Fallback / cross-cutting |

**Level:** `notice` (not `warning`). Apps that want to see deprecations must set `WAASEYAA_LOG_LEVEL=notice` or lower. The framework's default is `warning` to avoid noise for production operators; consumers on alpha/beta trains are expected to lower it.

**Format:** structured event with at minimum `channel`, `event`, `subject` (FQCN or symbol), `replacement` (FQCN or doc anchor), `removal_window` (alpha train range), `since` (alpha train that introduced the deprecation).

**Deduplication:** per-process, by `(channel, event, subject)`. Implementations may cache more aggressively as long as at least one notice fires per unique subject per process lifetime.

### 4.5 Removal windows

A deprecation cannot be removed before its window expires.

| Phase | Minimum window |
|---|---|
| Alpha | **3 alpha trains** from the train that introduced the deprecation |
| Beta | **2 beta trains** from the train that introduced the deprecation |
| Stable (`v1.x`) | Next major (`v(N+1).0`); intra-major removal is forbidden |

If the deprecating change ships in alpha train `N`, the earliest train that may remove the shim is `N+3`.

**Extension rule:** if the removal-target train arrives while a consumer-blocking issue against the new API is still open, the window extends by one train. This is the framework's contract that "the new way must actually work before the old way disappears."

### 4.6 Abbreviated path (alpha only)

For changes that materially cannot be shimmed (e.g. a fundamental contract shape change like the Symfony `Response` migration), the abbreviated path is:

1. Open a tracking issue tagged `breaking-change` with the technical infeasibility argument.
2. Land the change in a single PR alongside an upgrade-guide entry with a copy-pasteable migration recipe.
3. Tag the release with `## Breaking changes (no-shim)` in the release notes.
4. Maintainers must reply in the tracking issue to every "this broke my app" comment within 7 days for one alpha train.

The abbreviated path is forbidden in beta. In beta, "we can't shim it" means "we don't ship it yet."

---

## 5. Change classes & guarantees

This section enumerates the major contract families and the per-phase rules for each. All entries reference the deprecation cycle (§4) unless otherwise noted.

### 5.0 Definitions

- **Breaking change** — any change that causes correctly-written prior consumer code to error, behave differently in observable ways, or require source-code modification to recompile/restart.
- **Non-breaking change** — additive surface (new methods, new optional params, new events, new config keys with defaults), or a bug fix that aligns behavior with documented intent.

### 5.1 Routing / kernel / boot

**Stable surface:**
- `HttpKernel::handle(Request): Response` signature.
- `public/index.php` expectations: bootstrap kernel → handle request → call `$response->send()` unconditionally (see audit finding F7 / mission M6).
- Controller return type (`Symfony\Component\HttpFoundation\Response`).
- Controller parameter injection rules and the `$serviceMap` resolution order (or its declarative successor; see F4 / mission M4).
- Attribute-driven controller dispatch.

**Allowed under deprecation cycle:** all of the above.

**Forbidden in beta:** silent behavior changes to `handle()`, controller dispatch, or response emission. The WSOD incident (audit F7) must not recur — any change to the emit contract requires both a deprecation cycle *and* a body-size CI assertion in the test suite.

### 5.2 Console / command discovery

**Stable surface:**
- Provider capability interfaces (`HasNativeCommandsInterface` and successors).
- `CommandDefinition` shape.
- Console kernel boot path.
- `bin/waaseyaa` entry contract.

**Allowed under deprecation cycle:** interface renames, capability splits, signature changes.

**Forbidden in any phase:** silent drop of commands. If a provider would otherwise have contributed commands and now does not, the framework emits on `console.deprecation`. The alpha.173 anti-pattern (§4.3) is prohibited going forward.

### 5.3 Entity / storage

**Stable surface:**
- `EntityInterface`, `EntityStorageInterface`, `FieldableInterface` (and the publishable split of `get`/`set`; see audit F8 / mission M7).
- `EntityType` definition shape, including the `revisionable` flag (per ADR 016) and `entityKeys.revision` slot.
- `FieldDefinition` API, including `storedIn(string $backendId)` (per ADR 010).
- Access-policy attribute system (`PolicyAttribute`, `GateInterface`), including the `view_revision` operation (per ADR 016).
- **Field storage backend contract** — `FieldStorageBackendV2Interface` and the backend id namespace; ids `sql-blob`, `sql-column`, `vector` are reserved (per ADR 010).
- **Entity lifecycle events** — `BeforeSaveEvent`, `AfterSaveEvent`, `BeforeDeleteEvent`, `AfterDeleteEvent`, marker interface `EntityLifecycleEventInterface`, and `AbortOperationException` (per ADR 011). `EntityEvent` is non-`final` so the `TranslationEvent` sibling family may extend it (per M-006 / ADR 017).
- **Revisionable surface** — `RevisionableEntityInterface` (per ADR 016). (`RevisionableEntityStorageInterface` and the rest of the `vid` revision stack were removed in alpha.196.)
- **Translatable surface (M-006 / ADR 017):**
  - `Waaseyaa\Entity\TranslatableInterface` — `getTranslation`, `hasTranslation`, `addTranslation`, `removeTranslation`, `translations`, `defaultLangcode`, `activeLangcode`, `fieldLangcode`. `language()` is retained as a deprecated alias for `activeLangcode()`.
  - `Waaseyaa\Entity\Exception\EntityTranslationException` with named-constructor factories `langcodeRequired`, `cannotRemoveDefault`, `translationAlreadyExists`, `translationNotFound`.
  - `Waaseyaa\Entity\EntityType::__construct(...translatable: bool = false, ...)` — load-bearing flag enforced by boot validation.
  - `Waaseyaa\Field\FieldDefinition::translatable(bool $translatable = true): self` builder and `isTranslatable(): bool` reader.
  - `Waaseyaa\EntityStorage\SaveContext::withLangcode(string $langcode): self` immutable copy carrying the target langcode for translation writes.
  - `Waaseyaa\EntityStorage\EntityRepository::findTranslations(EntityInterface): array<string, EntityInterface>` and the matching method on `EntityRepositoryInterface` and `EntityStorageDriverInterface`.
  - `Waaseyaa\Access\ContextAwareAccessPolicyInterface` — companion to `AccessPolicyInterface` that accepts a `$context` array carrying langcode for the `'translate'` operation.
  - Access-policy operation literal `'translate'`.
  - `Waaseyaa\Entity\Event\TranslationEvent` and its six event-name constants: `PRE_TRANSLATION_INSERT`, `POST_TRANSLATION_INSERT`, `PRE_TRANSLATION_UPDATE`, `POST_TRANSLATION_UPDATE`, `PRE_TRANSLATION_DELETE`, `POST_TRANSLATION_DELETE`.
  - Entity key string `'default_langcode'` — required key in the `keys` array for translatable types.
  - Config keys `translation.fallback_chain` (array of langcodes) and `translation.read_active_language` (bool) — both documented in shipped `config/*.php`.
- **Two-axis (revisionable × translatable) surface (M-004 / ADRs 016 + 017):**
  - `EntityType::__construct(..., revisionable: bool = false, translatable: bool = false, ...)` — when **both** flags are `true`, storage emits two coordinated tables (`<entity>__revision` non-translatable + `<entity>__translation__revision` translatable, per-`(entity_id, langcode, vid)` row). Single-axis output is byte-for-byte unchanged (spec §12.3 R-A).
  - `Waaseyaa\EntityStorage\SaveContext::withTranslations(array $langcodes): self` — immutable copy carrying a `[langcode => values]` map for **atomic multi-language revision writes**. Empty map is rejected.
  - `Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler` — emits the `<entity>__translation__revision` table. Pairs with `RevisionTableBuilder::buildTwoAxis()`.
  - `Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver` — driver-level orchestrator composing `RevisionTableBuilder` + `TranslationSchemaHandler` for two-axis save/load.
  - `Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver` — listing-pipeline resolver that joins the two tables and applies langcode + revision-window selection.
  - `Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` — two-axis pruning policy value object (distinct from the M-001 `Waaseyaa\EntityStorage\RevisionPruningPolicy` single-axis policy; two-axis preserves per-language revision counts).
  - `Waaseyaa\Access\Policy\RevisionPolicyComposition` — composes entity-level and revision-level access policies for two-axis types; allows entity types to reuse their `view`/`update` policy across the new `view_revision` operation.
  - `Waaseyaa\EntityStorage\Exception\StorageMigrationException` — typed `\RuntimeException` subclass for storage-migration / two-axis schema failures. Closed factory surface; stable string `errorCode` values: `'no_op_promotion'`, `'unsupported_two_axis_field'`.
  - `Waaseyaa\Entity\Exception\EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode): self` — raised when a write targets a historical (non-tip) revision in a two-axis entity; stable `errorCode` `'historical_revision_write'`.
  - **Composite PK semantics.** The `<entity>__translation__revision` table carries a surrogate `vid PRIMARY KEY` for ergonomic `loadRevision($vid)` plus a composite `UNIQUE (entity_id, langcode, vid)` index expressing the logical primary key (R-01, `contracts/composite-pk.md`).
  - **Forbidden-backend guard.** Translatable fields routed to non-`sql-column` / non-`sql-blob` backends (`vector`, `remote`, etc.) raise `StorageMigrationException::unsupportedTwoAxisField()` at boot (FR-006).
  - **Independent per-language sequencing.** Revisions in different languages do not block each other: editing English produces only an English revision, editing Anishinaabemowin produces only an Anishinaabemowin revision. Non-translatable field changes propagate via fallback to all languages on read.

**Internal:**
- Concrete storage classes (`SqlEntityStorage`, future column-backed variants, vector-backend implementations).
- On-disk schema (SQLite/Postgres table shape, `_data` blob layout, column layouts, revision-table layout).
- Manifest cache file format.
- Storage coordinator fan-out logic across backends.
- Two-axis migration generator handlers (`Waaseyaa\CLI\Handler\AddTranslationsMigrationGenerator`, `Waaseyaa\CLI\Handler\AddRevisionsMigrationGenerator`) — invoked through stable `make:storage-migration` CLI surface; their class shapes are not stable.

**Special case — multi-backend storage migration (audit F1 / mission M3, ADRs 010 + 016):**

The current `_data` JSON-blob path is reframed as the `sql-blob` backend (ADR 010). Migration to column-backed fields is migration to the `sql-column` backend, not a removal of the blob entirely. The protocol:

1. **Introduce** `FieldStorageBackendV2Interface` and the `sql-blob` / `sql-column` backends as named, registered services.
2. **Co-design** revision-table layout in the same pass (per ADR 016) — column-backed entities and their revisions ship together; revisions are not retrofitted later.
3. **Shim** existing entity types — they continue on `sql-blob` until per-type opt-in to `sql-column`.
4. **Emit** an `entity.deprecation` notice on a per-entity-type basis identifying types still on `sql-blob` after a documented threshold train.
5. **Document** in a dedicated upgrade guide per entity-type migration (not bundled).
6. **Migrate** entity types incrementally, consumer-driven. The framework provides the migration generator; apps own the schedule.
7. **Remove** the `sql-blob` backend only when no shipped entity type uses it (cross-package check in CI). The `sql-column` backend stays indefinitely; `sql-blob` removal is the deprecation target, not all blob-like storage.

The "until usage reaches zero" grant from the previous draft applies specifically to **`sql-blob` backend usage**, not to JSON-shaped storage in general. Vector backends, key-value backends, and remote backends may also store opaque payloads; those are different backends with different contracts.

### 5.4 Manifest / compiler

**Stable surface:**
- `composer.json` `extra.waaseyaa.providers` schema.
- Provider discovery rules (PSR-4 scan paths, capability-interface detection).
- The manifest read API (not the file).

**Internal:**
- `PackageManifestCompiler` implementation details.
- `storage/framework/packages.php` file layout on disk.
- Cache invalidation triggers.

**Required (audit F4 / mission M4):**
- Cache invalidation hooks into `composer dump-autoload`. Manual `rm storage/framework/packages.php` is no longer required for new providers/policies to be discovered.
- Boot-time assertion: if `composer.json` providers list and compiled manifest disagree, fail loudly with a structured error (not via a separate parity test discovered only in CI).
- Registration silent-failure modes are forbidden. Every "you forgot to add X" path emits on `boot.deprecation` or fails with a typed exception, never both-fails-and-silent.

### 5.5 Config / env

**Stable surface — runtime read API:**
- Every config key referenced in shipped `config/*.php` files.
- Every env var documented in `README.md`, `CONTRIBUTING.md`, or per-package docs.
- `WAASEYAA_DB`, `WAASEYAA_LOG_LEVEL` (canonical examples).

**Stable surface — CMI sync substrate** (per [ADR 018](../adr/018-configuration-management-sync.md) and mission `config-management-v1-01KRCDEC` (M-003, 2026-05-16); subsystem spec [`config-management.md`](config-management.md)):

*Dependency declarations (`Waaseyaa\Config\Dependency\*`):*
- `ConfigDependencyInterface` — implemented by config-entity classes to declare `configDependencies(): array` (list of `<entity_type>.<entity_id>` strings).

*Sync-store file format:*
- File-naming convention `<entity_type>.<entity_id>.yml` (lowercase ASCII with `_` separators).
- Mandatory leading `_meta` block with keys `entity_type`, `uuid`, `dependencies` (string[]), `langcode`.
- Field-value alphabetical key ordering within `_meta` and within the top-level field group (deterministic git diffs).
- The `_meta` key vocabulary, the file-naming convention, and the field-type → YAML representation table (per spec §5.3) are load-bearing strings. They follow the §4 deprecation cycle even though they are not PHP types.

*Sync-store services (`Waaseyaa\Config\Sync\*`):*
- `ConfigSyncFile`, `ConfigSyncSerializer`, `ConfigSyncDeserializer`, `ConfigSyncRepository` — value object + format I/O.
- `ConfigExporter`, `ConfigImporter`, `ConfigDiffer`, `ConfigStatusReporter`, `ConfigSyncValidator`, `ConfigResetter` — orchestrators.
- `ConfigSyncFileSourceInterface`, `ConfigImportApplyHookInterface` — extension points.
- `ConfigManifestEntry` — manifest value object.

*Config keys:*
- `config.sync_path` (default `storage/config-sync/`) — root path for the sync store. Resolved relative to the project root.

*Audit log channel (`Waaseyaa\Config\Audit\*`):*
- `ConfigAuditChannel::CHANNEL` constant (value `'config.audit'`) — receives import / export / reset events and `--no-dependency-check` bypass warnings.
- `ConfigAuditEvent` — event payload shape (entity-type, id, operation, actor, before-after summary).

*Backend restriction (`Waaseyaa\Config\Backend\*`):*
- `BackendRestrictionEnforcer` with `ALLOWED_BACKEND_IDS = ['sql-blob', 'sql-column']` (boot-time gate) and `CONFIG_ENTITY_INTERFACE` reference constant.

*CLI namespace reservation (`Waaseyaa\CLI\Command\Config\*`):*
- Six reserved sub-verbs under the `config:*` namespace: `export`, `import`, `diff`, `status`, `validate`, `reset`.
- `ConfigCommand` abstract base exposes `RESERVED_VERBS`, `RESERVED_FULL_VERBS`, `RESERVED_FQCNS` constants for boot-time collision checks.
- Apps MAY register `config:<custom>` verbs that are NOT in the reserved set (e.g. `config:audit-export`); they own those.

*Exception classes (stable error model, charter §4.4 codes):*
- `Waaseyaa\Config\Dependency\Exception\ConfigDependencyCycleException`
- `Waaseyaa\Config\Dependency\Exception\ConfigDependencyMissingException`
- `Waaseyaa\Config\Exception\InvalidConfigBackendException`
- `Waaseyaa\Config\Exception\ConfigSerializationException`
- `Waaseyaa\Config\Exception\ConfigImportFailedException`
- `Waaseyaa\Config\Exception\ConfigCommandCollisionException`

**Internal (NOT stable surface — implementation detail):**
- `Waaseyaa\Config\Dependency\DependencyGraph` / `DependencyResolver` — algorithm; the failure modes (cycle / missing-dep) are stable via the exceptions above, but the topological-sort implementation may evolve.
- `Waaseyaa\Config\Sync\FieldValueMapper` — YAML representation for individual field-definition types; the mapping table in [`config-management.md`](config-management.md) §5.3 is stable, but the resolver class is refactorable.
- `Waaseyaa\Config\Sync\DiffResult`, `StatusEntry`, `StatusReport`, `FieldViolation`, `ConfigExportFileResult`, `ConfigExportResult`, `ConfigImportEntryResult`, `ConfigImportResult`, `ConfigValidateEntry`, `ConfigValidateResult` — internal value objects describing operator output; CLI output format is the contract, not the PHP shape.

**Allowed under deprecation cycle:**
- Renames of config keys, env vars, audit-channel event names (old name continues working, emits `config.deprecation`).
- Type tightening (e.g. boolean now required where string-coerced before).
- Renames of any sync-store key in `_meta`, any of the seven reserved `config:*` sub-verbs, or any of the six exception classes / their codes.

**Allowed without deprecation:**
- New keys with safe defaults.
- Removal of keys explicitly marked `@internal` in their config-file PHPDoc.
- New non-reserved `config:<custom>` CLI verbs registered by apps or extensions.
- Additive fields to `_meta` (new optional keys) and additive `FieldDefinition` types in the YAML mapping table.

**Forbidden:**
- Silent change to the sync-store file-naming convention (would break operator git history).
- Silent change to `_meta` block key vocabulary (would break consumer fixtures and CI gates).
- Silent change to the `config:*` reserved sub-verb list (would break operator muscle memory and recipes).
- Silent change to the `config.audit` channel name or event vocabulary (would break log-shipping configurations).

**Per-environment overrides:** charter §11 names runtime config overrides (`$config['x']['y']` style) as a future-ADR door. CMI does NOT ship them. The supported pattern for per-environment values is env vars consumed inside `config/waaseyaa.php` — documented prominently in [`config-sync.md`](../cookbook/config-sync.md).

### 5.6 Listing pipeline

Per [ADR 015](../adr/015-listing-pipeline-views-equivalent.md). Delivered by mission
`listing-pipeline-v1-01KRMN0B` (M-007, 2026-05-16). Subsystem spec:
[`listing-pipeline-v1.md`](listing-pipeline-v1.md).

**Stable surface (FQCNs in `Waaseyaa\Listing\` unless noted):**

*Value objects (`final readonly class`):*
- `Waaseyaa\Listing\ListingDefinition` — constructor signature + accessors.
- `Waaseyaa\Listing\FilterDefinition` — constructor + `exposedParam` clone semantics.
- `Waaseyaa\Listing\SortDefinition` — constructor + direction.
- `Waaseyaa\Listing\Pagination` — `$page`, `$pageSize`, `$totalRows`, `$totalPages`, `$hasPrev`, `$hasNext`.
- `Waaseyaa\Listing\ListingResult` — `rows()`, `pagination()`, `cacheTags()`, `cacheContexts()`.
- `Waaseyaa\Listing\ExposedFilterValues` — typed view over parsed `$_GET` slice.

*Factories (sibling sugar):*
- `Waaseyaa\Listing\Filter` — `eq()`, `neq()`, `lt()`, `lte()`, `gt()`, `gte()`, `in()`, `notIn()`, `isNull()`, `isNotNull()`, `between()`, `startsWith()`, `contains()`, `langcode()`, `exposed()`.
- `Waaseyaa\Listing\Sort` — `asc()`, `desc()`.

*Enums:*
- `Waaseyaa\Listing\Operator` — backed enum (`EQ`, `NEQ`, `LT`, `LTE`, `GT`, `GTE`, `IN`, `NOT_IN`, `IS_NULL`, `IS_NOT_NULL`, `BETWEEN`, `STARTS_WITH`, `CONTAINS`). Future additions are additive only.
- `Waaseyaa\Listing\SortDirection` — `ASC` | `DESC`.

*Services:*
- `Waaseyaa\Listing\ListingResolver` — `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult`.
- `Waaseyaa\Listing\ListingDefinitionRegistry` — `get(string $id): ListingDefinition` (throws `UnknownListingException` on miss).
- `Waaseyaa\Listing\ExposedFilterParser` — `parse(array $queryParams, ListingDefinition): ExposedFilterValues`.

*Capabilities:*
- `Waaseyaa\Listing\HasListingsInterface` — `public function listings(): array` returning `ListingDefinition[]`. Mirrors `HasNativeCommandsInterface` / `HasMigrationsInterface`.

*Exceptions:*
- `Waaseyaa\Listing\Exception\UnsupportedListingException` — definition-time validation failure (carries `listingId`, `fieldName`, `reason`).
- `Waaseyaa\Listing\Exception\UnknownListingException` — registry miss (carries `listingId`).

**Internal (NOT stable surface — implementation detail):**
- `Waaseyaa\Listing\ListingCacheKeyBuilder` — key format may change without notice.
- `Waaseyaa\Listing\ListingCacheInvalidator` — event-listener wiring; refactorable.
- `Waaseyaa\Listing\ListingDiscoverer` — boot-time discovery helper.
- `Waaseyaa\Listing\ListingDefinitionValidator` — boot-time validator.
- `Waaseyaa\Listing\ExposedFilterCoercer` — type-coercion implementation.
- `Waaseyaa\Listing\Exception\ListingCoercionException` — internal control-flow signal in the coercer.
- `Waaseyaa\Listing\EntityRepositoryRegistry` — bridge to entity-repository lookup; mission-internal.

**Allowed under deprecation cycle:** all stable surface above.

**Cross-cutting note — cache tags and contexts:** the listing pipeline forces cache tags + contexts to become stable surface in the `cache` package and a `RequestContext` to surface in `foundation`. See §5.9 (cache tag/context surface). Tag string format (`entity:<type>`, `entity:<type>:<id>`, `entity:<type>:<id>:<langcode>`) and canonical context names (`user.roles`, `user.id`, `language.content`, `language.interface`, `url.query.<param>`) are load-bearing strings — every consumer relies on them, so they are doc-level stable surface even though they are not PHP types.

**Forbidden:**
- Silent removal of pagination metadata (`Pagination` accessors).
- Silent change to filter parameter parsing rules (would break bookmarked URLs).
- Silent change to the `entity:*` tag vocabulary (would break every listing's cache-invalidation contract).
- Silent change to the `Operator` enum vocabulary (would break exposed-filter URLs and JSON-serialised definitions).

**Admin-composability** is explicitly *not* on stable surface in v0.x; a future ADR addresses the in-browser listing builder.

### 5.7 Themes

Per [ADR 014](../adr/014-theme-as-distributable-package.md).

**Stable surface:**
- Composer package type `waaseyaa-theme`.
- `composer.json` `extra.waaseyaa.theme` schema (`id`, `templates`, `public`).
- `theme.json` manifest format.
- Template resolution precedence: active theme → app templates → framework defaults. Shallow, fixed, no parent-theme chains.
- CLI commands: `bin/waaseyaa theme:install`, `theme:publish-assets`.
- Config key `theme.active` (one active theme per app).
- Published asset path convention (`/themes/<theme-id>/...`).

**Internal:**
- Asset publishing implementation details (symlink vs copy vs hardlink).
- Template-cache invalidation triggers.
- Theme-discovery scan order.

**Not in v0.x stable surface (deferred to future ADR):**
- Per-route or per-section theming.
- Parent-theme inheritance.
- Theme-emitted `<link>`/`<script>` tags (apps remain in control of their `base.html.twig`).

**Forbidden:** silent change to template precedence rules (would silently flip which file renders).

### 5.8 Migration platform

Per [ADR 012a](../adr/012a-migration-substrate-in-core.md). Delivered by mission
`migration-platform-v1-01KRCDE9` (M-002, 2026-05-13). Subsystem spec:
[`migration-platform.md`](migration-platform.md).

**Stable surface:**

*Plugin contracts (interfaces):*
- `Waaseyaa\Migration\Plugin\SourcePluginInterface` — streams `SourceRecord` from an external system.
- `Waaseyaa\Migration\Plugin\ProcessPluginInterface` — transforms one source value into one destination value.
- `Waaseyaa\Migration\Plugin\DestinationPluginInterface` — writes a `DestinationRecord` and supports `rollback()` + `lookup()`.

*Provider capabilities:*
- `Waaseyaa\Migration\Discovery\HasMigrationsInterface` — surfaces concrete `MigrationDefinition` instances.

*Value objects and DTOs (`final readonly class`):*
- `Waaseyaa\Migration\MigrationDefinition` — manifest object: id, source, process map, destination, dependencies.
- `Waaseyaa\Migration\SourceId` — composite primary key with deterministic sha256 hash.
- `Waaseyaa\Migration\Plugin\SourceRecord` — one record emitted by a source plugin.
- `Waaseyaa\Migration\Plugin\DestinationRecord` — one record ready to write.
- `Waaseyaa\Migration\Plugin\WriteResult` — outcome of a successful destination write.
- `Waaseyaa\Migration\Plugin\ProcessContext` — context threaded into `ProcessPluginInterface::transform()`.

*Concrete destination:*
- `Waaseyaa\Migration\Plugin\Destination\EntityDestination` — default destination; writes through the entity-storage coordinator (ADR 010).
- `Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory` — factory binding entity type + bundle.

*Source composition:*
- `Waaseyaa\Migration\Plugin\Source\FilteredSource` — lazy fixed-bundle source decorator that preserves wrapped-source identity semantics.

*Reserved process plugins (framework-owned ids):*
- `Waaseyaa\Migration\Plugin\Process\PassThroughProcessor` (`pass_through`).
- `Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor` (`html_sanitize`).
- `Waaseyaa\Migration\Plugin\Process\LookupProcessor` (`lookup`).
- `Waaseyaa\Migration\Plugin\Process\ConcatProcessor` (`concat`).
- `Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor` (`type_coerce`).
- `Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor` (`default_value`).
- `Waaseyaa\Migration\Plugin\Process\PartitionedLookupProcessor` (`partitioned_lookup`).

Reserved-id list canonicalised in `Waaseyaa\Migration\Plugin\ReservedPluginIds`. App-defined process plugins MUST use a non-reserved id; convention `<vendor>_<purpose>`.

*Schema:*
- `migration_id_map` table layout — frozen stable surface. Future column changes require a charter amendment and a data migration of every existing row. Source-of-truth descriptor: `Waaseyaa\Migration\Schema\MigrationIdMapSchema`.
- `Waaseyaa\Migration\MigrationIdMap::lookupDestination()` and `lookupDestinationAcross()` — read-only id-map access for source-reader composition; all mutation and traversal methods remain mission-internal.

*Exception types:*
- `Waaseyaa\Migration\Exception\MigrationCycleException`
- `Waaseyaa\Migration\Exception\MigrationPluginCollisionException`
- `Waaseyaa\Migration\Exception\MigrationDependencyMissingException`
- `Waaseyaa\Migration\Exception\SourceReadException`
- `Waaseyaa\Migration\Exception\ProcessException`
- `Waaseyaa\Migration\Exception\DestinationWriteException`
- `Waaseyaa\Migration\Exception\MigrationAbortedException`
- `Waaseyaa\Migration\Exception\MigrationConcurrencyException`

*CLI commands:*
- `bin/waaseyaa import:run <id>` — run one migration end-to-end.
- `bin/waaseyaa import:run-all` — run every registered migration in dependency order (lock acquired per migration).
- `bin/waaseyaa import:status [<id>]` — read-only status (no lock).
- `bin/waaseyaa import:resume <id>` — resume from last committed `migration_run_state` cursor.
- `bin/waaseyaa import:rollback <id>` — walk id-map in reverse, unwind destination, remove id-map rows on success.
- `bin/waaseyaa import:reset <id>` — drop id-map rows without rollback (recovery only).

Exit codes: `0` success, `1` generic failure, `2` lock held by another process.

*Conformance test bases (autoload-dev under `packages/migration/testing/`):*
- `Waaseyaa\Migration\Testing\SourceConformanceTestCase` — eight gates: id format, stability, streaming, determinism, `count()` contract, 50 MB memory budget, etc.
- `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` — round-trip, idempotency, rollback semantics.

*Log channel:*
- `migration.deprecation` — emitted once per process when an `'experimental'` plugin is first used. Constant: `Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION`. Listed under §4.4 as a normative channel name.

*Cross-cutting extension to §5.3 (Entity / storage):*
- `Waaseyaa\EntityStorage\SaveContext::isImport(): bool` — additive method added by M-002. `EntityDestination::write()` constructs the context with `isImport: true`. Default is `false`; existing subscribers behave unchanged. See §5.3.

**Internal (NOT stable surface — mission-internal infrastructure):**
- `migration_run_state` table (resume cursor storage; runner-internal).
- `Waaseyaa\Migration\Schema\MigrationRunStateSchema` (mission-internal).
- `Waaseyaa\Migration\Runner\MigrationRunner` and all classes under `Waaseyaa\Migration\Runner\` (`MigrationLock`, `ProcessChainExecutor`, `RollbackWalker`, `RecordError`, `RollbackError`, `RollbackReport`, `RunOptions`, `RunReport`).
- `storage/migration-locks/<id>.lock` file format (flock-based; lock-file presence is operator-visible but the format is not a published contract).
- `Waaseyaa\Migration\Discovery\MigrationRegistry`, `CycleDetector`, `DependencyGraph`, `FilesystemManifestLoader` (boot-time discovery internals).
- `Waaseyaa\Migration\MigrationIdMap` mutation, deletion, transaction, count, and reverse-walk methods. Only the two read-only lookup methods named above are stable source-reader composition seams.
- `Waaseyaa\Migration\Canonical\CanonicalForm` (helper backing `SourceId::hash()`; behaviour is stable transitively, but the class is not a public extension point).

**Operator-visible but not contractual:**
- The `migration_run_state` table is mission-internal — apps and third-party tools MUST NOT query or write to it directly. Use `bin/waaseyaa import:status` and `import:resume` instead.
- Stale-lock recovery is documented in the spec (`rm storage/migration-locks/<id>.lock`) but is not an SLA — the lock-file location and format may change between alpha trains.

**Allowed under deprecation cycle:** all stable surface above.

**Forbidden:**
- Silent removal of any listed FQCN (interfaces, value objects, concrete destinations, reserved process plugins, exceptions, conformance bases).
- Silent change to `migration_id_map` schema (would invalidate every existing import).
- Silent change to `SourceId::hash()` canonical-form encoding (would silently re-import every record on upgrade — explicitly guarded by the encoding-flag set documented in `migration-platform.md` §6.1).
- Silent change to CLI command names or exit codes.
- Silent change to the `migration.deprecation` channel name.

**Cross-references:**
- [ADR 010](../adr/010-multi-backend-field-storage.md) — entity-storage coordinator that `EntityDestination` writes through.
- [ADR 011](../adr/011-entity-lifecycle-events.md) — lifecycle events; `SaveContext::isImport()` extends this contract.
- [ADR 012a](../adr/012a-migration-substrate-in-core.md) — origin ADR.
- [ADR 016](../adr/016-revisions-first-class.md) — revisionable storage path used by `EntityDestination` when the destination entity type opts in.

### 5.9 Cache tag-aware operations and context registry

Per [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) §Consequences. Surface ratified by mission
`listing-pipeline-v1-01KRMN0B` (M-007, 2026-05-16). The listing pipeline (§5.6) is the first
load-bearing consumer; cache tags + context names are doctrinal vocabulary shared by every future
listing, render-cache, and HTTP-cache surrogate.

Subsystem references: [`listing-pipeline-v1.md`](listing-pipeline-v1.md),
[`../conventions/cache-tags-and-contexts.md`](../conventions/cache-tags-and-contexts.md).

**Stable surface:**

*Interfaces and exceptions (`Waaseyaa\Cache\`):*
- `Waaseyaa\Cache\TaggedCacheInterface` — extends `Waaseyaa\Cache\CacheBackendInterface` with `setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void`, `invalidateByTag(string $tag): int`, `getTagsFor(string $key): array`.
- `Waaseyaa\Cache\Exception\InvalidCacheTagException` — thrown by `setWithTags()` on malformed tag strings (no silent normalisation).

*Services and registry (`Waaseyaa\Cache\`):*
- `Waaseyaa\Cache\ContextRegistry` — whitelist of context names. `register(string $name): void`, `has(string $name): bool`, `all(): array`.
- `Waaseyaa\Cache\ContextResolver` — `resolve(string $context, RequestContext $request): string`. Deterministic short-string resolution for a context name in the active request.

*Canonical context names (`Waaseyaa\Cache\ContextNames` constants):*
- `ContextNames::USER_ROLES` = `'user.roles'`
- `ContextNames::USER_ID` = `'user.id'`
- `ContextNames::LANGUAGE_CONTENT` = `'language.content'`
- `ContextNames::LANGUAGE_INTERFACE` = `'language.interface'`
- `ContextNames::URL_QUERY_PREFIX` = `'url.query.'` (concatenate the query-param name; e.g. `ContextNames::URL_QUERY_PREFIX . 'page'` → `'url.query.page'`).

*Request-context bridge (`Waaseyaa\Foundation\Http\`):*
- `Waaseyaa\Foundation\Http\RequestContext` — interface exposing the current request's roles, user id, active language, and query parameters to `ContextResolver` without leaking the underlying HTTP stack. Stable signature; the concrete request implementation may vary by host (PSR-7, native, CLI fixture).

*Doc-level rules (load-bearing strings — strings, not types, but covered by the deprecation policy):*
- Tag-string format regex: `^[a-z][a-z0-9_:.-]*$`. Enforced by `TaggedCacheInterface::setWithTags()`.
- Context-name format regex: `^[a-z][a-z0-9_.]*$`.
- Canonical tag vocabulary:
  - `entity:<type>` — invalidates every cached entry for an entity type.
  - `entity:<type>:<id>` — invalidates entries scoped to a single entity.
  - `entity:<type>:<id>:<langcode>` — invalidates per-translation entries on translatable entity types.

**Internal (NOT stable surface):**
- Tag-index storage layout inside any individual `TaggedCacheInterface` implementation (e.g. `MemoryBackend`'s reverse index format is internal).
- The integer return value of `invalidateByTag()` is best-effort; consumers MUST NOT branch on the exact count.

**Allowed under deprecation cycle:**
- Adding new constants to `ContextNames` (additive).
- Adding new methods to `ContextRegistry` (additive).

**Forbidden:**
- Silent removal of any canonical tag vocabulary line above (would silently break cache-invalidation invariants for every listing consumer).
- Silent renaming of any `ContextNames` constant (constants are exported by FQCN — apps reference them directly).
- Silent change to the tag-string regex (would retroactively reject previously-accepted tags).
- Silent removal of `setWithTags`, `invalidateByTag`, or `getTagsFor` from `TaggedCacheInterface`.

**Cross-references:**
- §5.6 — the listing pipeline is the first stable consumer.
- [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) §Consequences — names this section as the forcing function that committed the framework to a cache-tags-and-contexts architecture.

---

## 6. Consumer obligations

Apps and extensions that meet these obligations receive supported-upgrade treatment: deprecation visibility, upgrade-guide coverage, and a "this broke my app" response window.

### 6.1 Sync line currency

Consumers must stay within **N=3 alpha trains** of `main`. Apps further behind may upgrade, but cannot expect linear upgrade guides — they may need to apply multiple guides in sequence, and may hit deprecations that have already been removed.

`N` shrinks to 2 in beta and 1 in stable.

### 6.2 Run upgrade guides

Every upgrade across one or more alpha trains requires applying the corresponding upgrade guide(s) in `docs/upgrades/`. Skipping the guide forfeits supported status for any issue traceable to a skipped step.

### 6.3 Monitor deprecation channels

Consumers on alpha/beta trains must:

- Set `WAASEYAA_LOG_LEVEL` to `notice` (or lower) in at least one environment where their app exercises representative traffic (CI, staging, or a dev loop).
- Periodically grep their logs for the channels in §4.4 and feed the output to an issue tracker.
- Resolve each deprecation before the removal window for that deprecation closes.

The audit's M9 (implicit-array backlog grep) is the template for this practice.

### 6.4 Respect removal windows

Consumers cannot demand a removal-window extension as a matter of right. Extensions may be granted under §4.5 when the new API is materially broken; "we didn't get to it" is not grounds.

### 6.5 File bugs against new APIs promptly

The §4.5 extension rule only triggers on issues that are *open* against the new API. Consumers who discover a new-API bug must file it against the framework repo, not work around it in app code and silently delay upgrading.

---

## 7. Upgrade guide template

Every release with a breaking change or deprecation ships an upgrade guide at:

```
docs/upgrades/waaseyaa-alpha-<FROM>-to-<TO>.md
```

(or `…-beta-…`, `…-v1-…`). The guide is the consumer-facing translation of the release's breaking changes. It is the source of truth for any "what do I do" question — release notes link to it; the changelog links to it; this charter links to it.

### 7.1 Skeleton

```markdown
# Upgrade Guide: waaseyaa alpha.<FROM> → alpha.<TO>

**Released:** YYYY-MM-DD
**Migration effort:** small | medium | large
**Required for:** all consumers | apps using <feature> | extensions only

## Summary

One paragraph: what changed at a high level, why, and what an app needs to do.

## Breaking changes

Each entry:

### <symbol or contract>

- **What changed:** old behavior → new behavior, in one line.
- **Detection:** how to find affected code (grep recipe, log channel, CI check).
- **Migration:** copy-pasteable patch or recipe. Code blocks before/after.
- **Shim status:** active until alpha.<X> | no shim (abbreviated path §4.6).
- **Tracking issue:** #<num>

## Deprecations

Each entry:

### <symbol>

- **What is deprecated:** the surface being phased out.
- **Replacement:** the new surface.
- **Notice channel:** one of §4.4.
- **Removal window:** earliest alpha train that may remove the shim.
- **Detection grep:** copy-pasteable command to find usages.

## Required app migrations

Ordered list of steps an app must take. Each step:

1. **Action.** What to do.
2. **Verification.** How to confirm it took effect (test, command, log absence).

## Removal of shims

List of shims removed in this train (i.e. the `removed` fragments compiled into `CHANGELOG.md`). Each entry:

### <symbol>

- **Was deprecated in:** alpha.<N>
- **Replaced by:** <new symbol>
- **If you still depend on it:** stay on alpha.<TO-1> until you migrate.

## Verification checklist

- [ ] `composer update 'waaseyaa/*'` succeeds.
- [ ] App's test suite passes.
- [ ] No new entries in deprecation log channels relevant to this guide.
- [ ] Upgrade-guide steps completed and verified.

## Release notes pointer

Full release notes: <link to GitHub release or CHANGELOG anchor>
```

### 7.2 Per-package notes

When a single alpha train ships changes across multiple packages, the upgrade guide is split into per-package sections under `## Breaking changes` and `## Deprecations`. The skeleton is otherwise unchanged.

### 7.3 Retroactive guides

For alpha trains already shipped without a guide (alpha.106, alpha.107, alpha.173, alpha.175 are the named cases in the audit), maintainers may publish retroactive guides at the same path with a `**Retroactive:**` header note. Retroactive guides are not enforced by CI but are tracked in the framework roadmap.

---

## 8. Enforcement hooks

This charter is enforced by tooling, not by promise. The mechanisms below ship as part of mission M1 (this charter's implementation phase) and gate all subsequent merges.

### 8.1 CI: public-surface-map parity

A CI job runs on every PR:

1. Scans the framework source for exported symbols (classes, interfaces, public methods, public consts) in non-`Internal\` namespaces.
2. Cross-checks against `public-surface-map.php`.
3. Fails on:
   - Symbol present in source, missing from map (untracked surface).
   - Symbol present in map, missing from source (removal without deprecation entry).
   - Symbol's classification downgraded (`public` → `internal`) without a deprecation entry in the matching changelog.

Removal and deprecation authority is machine-verifiable and scoped to the
current change. The gate compares `docs/public-surface-map.php` with the merge
base, including governed concrete final classes that cannot be inferred from
declaration shape. An authorization must be a line in a fragment newly added by
the candidate. The fragment type supplies the release section, using one of
these exact forms:

```markdown
- Public surface deprecation: `Waaseyaa\Package\Contract`
- Public surface removal: `Waaseyaa\Package\Contract`
- Public surface rename: `Waaseyaa\Package\OldContract` -> `Waaseyaa\Package\NewContract`
```

FQCNs are case-sensitive and must be complete. A rename replacement must be
present, mapped, and loadable in the candidate. Bare leaf names, Added or
Changed entries, prose, examples, links, fragments already present on the base,
and entries in historical release sections carry no authority. A `deprecated`
fragment authorizes a `public` disposition downgrade while the type remains loadable;
actual source/map removal requires a Removed directive after the governed
deprecation cycle.

### 8.2 CI: changelog discipline

Every PR that touches a public-surface file must add or edit one of:

- A validated `changes/unreleased/<issue>.<unique-slice>.<type>.md` fragment.
- An upgrade-guide file under `docs/upgrades/`.

CI fails the PR if neither is touched. Pending fragments are append-only and
ordinary PRs cannot edit root `CHANGELOG.md`; historical maintenance requires
the documented `changelog-maintenance:` PR marker. This is a soft check — the
merge checklist (§8.3) is the final gate, and maintainers may override release
prose with a documented `no-changelog:` rationale.

### 8.3 Merge checklist for breaking changes

Maintainers merging a PR labeled `breaking-change` must confirm each item:

```
- [ ] The change is justified in the PR description; alternatives considered.
- [ ] Affected surface tier identified (stable / provisional / internal).
- [ ] If stable: deprecation cycle applied per §4 (shim, notice, doc, removal window).
- [ ] If shim infeasible: §4.6 abbreviated path used; infeasibility argued.
- [ ] Upgrade-guide entry written (§7) under docs/upgrades/.
- [ ] Tracking issue exists and is linked.
- [ ] Public-surface-map updated.
- [ ] Changelog fragment uses the correct release type.
- [ ] CI green on `surface-parity` and `changelog-discipline` jobs.
- [ ] At least one non-author maintainer reviewed the breaking-change section of the PR description.
```

The checklist lives in `.github/pull_request_template.md` under a collapsible `<details>` block, expanded automatically when the `breaking-change` label is applied.

### 8.4 Release notes requirement

Every tagged alpha/beta/stable release includes a `## Breaking changes` section, even if empty (in which case it reads "_None._"). Empty sections are honest. Missing sections are charter violations.

### 8.5 Periodic audits

Maintainers run a charter audit each calendar quarter:

- Active deprecations vs removal windows: anything overdue gets a tracking issue.
- Provisional symbols past their two-clean-trains threshold: reclassify.
- Surface-map drift: items found in source but unlabeled.
- Consumer log-channel evidence: did the audit's referenced channels (`dispatcher.deprecation` etc.) fire in any consumer's CI for un-fixed deprecations?

The audit output is a section appended to this charter's `## Charter audit log` (§9), not a separate document. The cadence is enforced by a scheduled CI job that opens an issue if no audit entry has been added in 100 days.

---

## 9. Charter audit log

_No entries yet._

Future audits append entries with date, summary of findings, and links to issues opened.

---

## 10. Cross-references

- [`VERSIONING.md`](../VERSIONING.md) — release stages, tag policy, v1.0 sign-off (authoritative).
- [`public-surface-map.md`](../public-surface-map.md) — surface enumeration (substrate for §2).
- [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) — mission-completeness measurement against Drupal as the reference class (substrate for §2.6 and §3.2 criterion 9).
- [`specs/extension-release-playbook.md`](extension-release-playbook.md) — extension publishing process.
- 2026-05-11 framework/app audit (in `waaseyaa/minoo` repo, `docs/audits/2026-05-11-framework-app-audit.md`) — origin findings F3 and F4.

### Governing ADRs

The following ADRs add stable surface contracts that this charter governs. Each is referenced inline in the section it affects.

- [ADR 010](../adr/010-multi-backend-field-storage.md) — multi-backend field storage (governs §5.3).
- [ADR 011](../adr/011-entity-lifecycle-events.md) — entity lifecycle events (governs §5.3, log channel in §4.4).
- [ADR 012a](../adr/012a-migration-substrate-in-core.md) — migration platform: substrate in core, source readers as packages (supersedes ADR 012 under parity-with-Drupal-12 reframe; WordPress reader first-party priority).
- [ADR 013](../adr/013-form-abstraction-apps-own.md) — forms as app concern (positions a deliberate non-surface; intersects §5.5 validation).
- [ADR 014](../adr/014-theme-as-distributable-package.md) — theme as composer package (governs §5.7).
- [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) — listing pipeline (governs §5.6; gates §3.2 criterion 7).
- [ADR 016](../adr/016-revisions-first-class.md) — revisions first-class (governs §5.3 revisionable surface; gates §3.2 criterion 8).

---

## 11. Open questions (historical — all resolved)

**STATUS: All twelve questions resolved (2026-05-11).** The charter is in force (§12); there is no ratification gate. The Q3 CI infrastructure has landed and is enforced (`surface-parity` blocking, `ci/verify-gates` running the full `composer verify` set).

1. **~~`public-surface-map.php` schema bump~~** — **RESOLVED (2026-05-11).** Additive schema bump: each surface entry gains an optional `mission_status` field (`present | partial | planned | intentional-gap`) alongside the existing tier field. Forward-compatible — tooling that reads only the tier field continues working without modification. Schema migration lands in the pre-ratification PR alongside the Q3 CI infrastructure. Not a breaking change to any consumer.
2. **~~Log layer support~~** — **RESOLVED (2026-05-11).** Confirmed: the Foundation `LoggerInterface` supports the `(channel, event, subject)` shape via PSR-3-compatible `$context` arrays. The `entity.lifecycle` channel (per ADR 011), `migration.deprecation` and `config.audit` channels (per ADRs 012a / 018), and the existing `dispatcher.deprecation` / `boot.deprecation` channels all follow the same shape. Pre-ratification verification step: enable `WAASEYAA_LOG_LEVEL=notice` in CI and confirm sample emissions parse to the documented event schema.
3. **~~CI infrastructure~~** — **RESOLVED (2026-05-11).** `surface-parity` and `changelog-discipline` jobs authored at `.github/workflows/surface-parity.yml` and `.github/workflows/changelog-discipline.yml`. Supporting scripts at `tools/check-surface-parity.php` and `tools/check-changelog-discipline.sh`. Owner: framework maintainers (`@jonesrussell`). Both jobs gate PRs to `main` per §8.1 and §8.2. Land in the ratification PR.
4. **~~Retroactive upgrade guides~~** — **RESOLVED (2026-05-11).** Deferred to a follow-up "retroactive-upgrade-guides" meta-mission. Charter ratification does NOT gate on retroactive guides for alpha.106 / alpha.107 / alpha.173 / alpha.175. Rationale: retroactive guides are orthogonal to the charter mechanism; their absence does not weaken the contract; bundling them in would delay ratification by weeks for documentation already-superseded by code state. Tracked as a separate post-ratification deliverable.
5. **~~Beta entry criterion 3 (third-party consumer)~~** — **REMOVED (2026-06-15, solo-dev charter strip).** The original resolution defined "one non-Minoo consumer" as a real, non-Waaseyaa-org third-party consumer through an upgrade cycle. That entry criterion has been **deleted** from §3.2: Waaseyaa is a single-developer project and an external-adoption gate is dead weight. The beta bar now depends only on code health and enforced CI.
6. **~~Removal window for the `sql-blob` backend~~** — **RESOLVED (2026-05-11).** Soft-cap: **12 alpha trains beyond beta entry, OR until `sql-blob` backend usage in the consumer ecosystem reaches zero, whichever is sooner**. Honors ADR 010's "until usage reaches zero" intent for early beta phases while preventing indefinite blob-backend support tax in late beta and v1.x. Charter §5.3 special-case wording updated to reflect the cap. Mission spec `entity-storage-v2.md` references this resolution for migration scheduling.
7. **~~Unresolved §3.2 critical-gap criteria~~** — **RESOLVED.** Matrix §3.2 (per-field translation) is governed by [ADR 017](../adr/017-per-field-translation.md); matrix §3.5 (CMI / config sync) is governed by [ADR 018](../adr/018-configuration-management-sync.md). Both accepted 2026-05-11. Beta entry criterion 9 fully clearable.
8. **~~Cache tags + contexts package ownership~~** — **RESOLVED (2026-05-11).** Cache tags and contexts are owned by the `cache` package and made stable surface as part of ADR 015's listing-pipeline mission implementation (TBD `listing-pipeline-v1.md`). No separate "cache invalidation v2" ADR required. Tag format: `entity:<type>:<id>` and `entity:<type>:<id>:<langcode>` (per ADR 017 / `entity-storage-translatable-revisions.md` FR-032). Context names: `user.roles`, `url.query.<param>`, `language.requested`. The listing-pipeline mission spec carries these as acceptance criteria when it drafts.
9. **~~Backend ID namespace policy~~** — **RESOLVED (2026-05-11).** Framework owns the reserved built-in namespace: `sql-blob`, `sql-column`, `vector`, `remote`. Apps and packages MAY register backends under any non-reserved id; recommended convention `<vendor>-<purpose>` (e.g. `minoo-elasticsearch`, `acme-tigerbeetle`). Collision detection at boot via `BackendIdCollisionException` (entity-storage-v2 FR-005). No separate registration API beyond the existing `HasFieldStorageBackendsV2Interface` capability.
10. **~~`storage-coordinator` event-fan-out semantics~~** — **RESOLVED.** Per `docs/specs/entity-storage-v2.md` FR-024: `AfterSaveEvent` does NOT fire on partial-save failure; partial-save raises `PartialSaveException` with both committed and uncommitted backend lists. Charter §5.3 surface inherits the resolution from the mission spec.
11. **~~CLI namespace consolidation~~** — **RESOLVED (2026-05-11).** Deferred to a separate post-migration-platform ADR. Current disambiguation (`import:*` for data migration, `migrate:*` for schema migration) is workable and ships in v0.x. Charter ratification does NOT gate on consolidation. Rationale: pre-ratification renames create deprecation churn that yields no consumer-visible benefit until the migration platform actually ships. Consolidation question tracked as a v1.x candidate ADR after both migration platform and schema migration are mature.
12. **~~`config:*` command namespace reservation~~** — **RESOLVED (2026-05-11).** The `config:*` verb namespace is reserved framework-side. Reserved sub-verbs: `export`, `import`, `diff`, `status`, `validate`, `reset` (per ADR 018). Apps registering conflicting commands fail at boot via `ConfigCommandCollisionException` (config-management-v1 FR-048). Mechanism: registration-time collision check in the CLI kernel, parallel to the backend-id collision check (entity-storage-v2 FR-005).

### Deferred to future ADRs (named exits)

These are explicitly named exit points so they're not lost. Items with named follow-up missions are flagged.

- **Content moderation workflows** (states + transitions + approval queues; consumes [ADR 016](../adr/016-revisions-first-class.md); blocked on revisions shipping in entity-storage-v2 first).
- **Translation-provider integrations** (Google Translate / DeepL / etc.; not governed by [ADR 017](../adr/017-per-field-translation.md), which keeps these out of scope).
- **Config translation** (interaction between [ADR 018](../adr/018-configuration-management-sync.md) and [ADR 017](../adr/017-per-field-translation.md); deliberately deferred in both).
- **Per-environment config-store overrides** (Drupal `$config['x']['y']` runtime overrides; deferred by ADR 018).
- **Multi-theme / per-route theming** (extends [ADR 014](../adr/014-theme-as-distributable-package.md)).
- **Cross-backend query coordination** (joins / multi-backend filters; extends [ADR 010](../adr/010-multi-backend-field-storage.md) + [ADR 015](../adr/015-listing-pipeline-views-equivalent.md)).
- **Listing builder admin UI** (extends [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) post-v1.0).
- **CLI namespace consolidation** (`migrate:*` vs `schema:*` rename; named in §11 Q11).
- **Incremental / continuous source-sync** (extends [ADR 012a](../adr/012a-migration-substrate-in-core.md); v0.x ships one-shot only).

### Mission specs named by accepted ADRs (drafted or pending)

These are the implementation missions that operationalize the accepted ADRs. The charter does not own these; they live as separate mission specs in `docs/specs/`.

- **`entity-storage-v2.md`** — drafted (2026-05-11). Implements [ADRs 010 / 011 / 016](../adr/) as one coordinated mission. Validates with Minoo `teaching` entity migration.
- **`migration-platform-v1.md`** — pending draft. Implements [ADR 012a](../adr/012a-migration-substrate-in-core.md) substrate. First-party source readers (WordPress, Drupal 7) follow as separate package-mission specs.
- **`config-management-v1.md`** — pending draft. Implements [ADR 018](../adr/018-configuration-management-sync.md).
- **`entity-storage-translatable-revisions.md`** — pending draft. Implements [ADR 017](../adr/017-per-field-translation.md)'s revisionable+translatable interaction. Lands after `entity-storage-v2.md`.

---

## 12. Status — in force

This charter is **in force**. As a single-developer project, it takes effect by being committed to `main` and followed — there is no ratification vote, sign-off PR, or owner-approval artifact gating it (that ceremony was removed 2026-06-15; it was dead weight for a one-person project).

Two engineering items remain to fully satisfy the §3.2 *beta* bar (they do not gate the charter being in force for *alpha*):

1. `public-surface-map.php` items fully tier-labeled per §2.5 (tracked as audit C-15).
2. The enforcement hooks of §8 wired and green on `main` — **done**: `surface-parity` is a real blocking AST gate, and the full `composer verify` gate set runs in CI (`ci/verify-gates`).

The charter's guarantees bind the framework's own development now; consumers gain binding stable-surface guarantees at beta entry (§3.2).
