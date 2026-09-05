# Waaseyaa ↔ Drupal Feature Comparison Matrix

**Status:** Draft (input to the [stability charter](stability-charter.md))
**Purpose:** Measure Waaseyaa's surface against Drupal as the actual reference class. The Laravel-snob audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) measured the wrong reference class — Waaseyaa isn't trying to be Laravel. It's positioned as an entity-first, AI-first PHP 8.5+ framework that obsoletes both Drupal and Laravel without inheriting their debt. This document maps Drupal's feature surface onto Waaseyaa so that the stability charter governs a surface whose **completeness** is also measured, not just its API shape.

---

## 0. Methodology

### 0.1 Reference

Drupal 11 (current as of 2026-05) is the comparator. Where a feature lives in Drupal contrib rather than core (e.g. Search API, JSON:API in older versions, Rules), it's flagged as such.

### 0.2 Status legend

| Code | Meaning |
|---|---|
| ✅ **Present** | Shipping in a package on the public-surface-map; usable today |
| 🟡 **Partial** | Foundations exist but the feature is incomplete from a Drupal-equivalence standpoint |
| 📋 **Planned** | Known commitment with a roadmap entry, issue, or mission |
| ❌ **Intentional gap** | Deliberately not implementing; the framework will leave this to apps or contrib |
| ❓ **Unknown** | Needs an owner decision — captured in §6 |

### 0.3 What "equivalence" means here

Drupal's feature set is the result of 20 years of accretion. Equivalence does not mean "every Drupal feature, identically." It means: a Drupal-shop CTO asking "can I run my use case on Waaseyaa instead?" can answer the question. A 🟡 or ❌ is a fine answer as long as it's an answered question.

### 0.4 What this matrix does and doesn't do

- **Does:** enumerate, categorize, surface decisions needed.
- **Does not:** prescribe a build order. Build order is a mission-planning concern.
- **Does not:** assess code quality of what exists. That's the audit's job.

---

## 1. The matrix

### 1.1 Entity & data model

| Drupal feature | Status | Waaseyaa surface | Notes |
|---|---|---|---|
| Content entities | ✅ | `entity` (Layer 1), `ContentEntityBase` | Native PHP 8.4 attributes vs annotations; cleaner. |
| Config entities | ✅ | `config`, `ConfigEntityBase` | |
| Bundles | ✅ | EntityType bundle key | Implementation parity. |
| Field definitions | ✅ | `field`, `FieldDefinition` | |
| Field storage backends | 🟡 | `entity-storage` (SQL) | Currently SQL only via `_data` blob. Column-backed fields planned (audit M3). Drupal supports multiple backends including key/value and remote — see §6.1. |
| Field type plugins | 🟡 | `FieldDefinition` types | Type system exists; the pluggability story (custom field types from apps) is unverified. |
| Entity revisions | ❌📋 | — | **Not currently present.** Critical for Minoo Knowledge Keeper editorial flow. See §3.1. |
| Entity translations | 🟡 | `langcode` field on entities; `i18n` package (Layer 0) | Schema has `langcode`. Per-field translatability flag, fallback chain, translation API — surface unclear. See §3.2. |
| Entity query API | ✅ | `EntityStorageInterface::getQuery()` | |
| TypedData | ✅ | `typed-data` (Layer 0) | |
| Entity hooks/events | ❓ | — | What event names are stable surface? CRUD events emitted? See §6.2. |
| Default content (config) | ✅ | `App\Seed\*` | Seeders pattern is cleaner than Drupal's `default_content` contrib. |

### 1.2 Field API — the three sides

Drupal's Field API has **storage** (you have), **widget** (form input), and **formatter** (rendered output). Waaseyaa has the storage side. The widget/formatter story is the largest quiet gap.

| Side | Status | Notes |
|---|---|---|
| Storage | ✅ | `FieldDefinition` + `EntityStorage`. M3 will column-back. |
| Widget (form input) | ❓ | No `form` package on the surface map. SSR layer handles forms today, but is there an abstraction for "render this field as input"? If the answer is "write Twig per field," that's a position — needs to be made explicit. |
| Formatter (rendered output) | ❓ | Same — Twig partials in `templates/components/` work for one app. Cross-app reuse without a formatter abstraction means each app rewrites display logic. |
| View modes | ❌❓ | Drupal's "teaser / full / search_index / RSS" abstraction. Waaseyaa apps achieve this by template selection. Is that intentional? Documenting the position matters. |

**Implication:** If Waaseyaa positions itself as Drupal-without-debt, the field widget/formatter abstraction is the single largest missing piece. The audit didn't surface this because Laravel doesn't have it either. See §3.3.

### 1.3 Access control

| Feature | Status | Surface | Notes |
|---|---|---|---|
| Entity access | ✅ | `access` (Layer 1), `#[PolicyAttribute]` | Attribute-based policies beat Drupal's `hook_entity_access` decisively. |
| Field-level access | ❓ | — | Drupal supports per-field access (e.g. only Coordinators see a private notes field). Does Waaseyaa? |
| Role / permission system | 🟡 | Identity layer in apps; framework `access` | Permission set + role assignment surface unclear at framework level. Apps appear to roll their own. |
| Operation context (view/edit/delete + custom) | ✅ | Policy method signatures | |
| Bundle-scoped access | ✅ | PolicyAttribute supports per-bundle | |

### 1.4 Views (query + display)

Drupal's killer feature for 15 years: declarative query → filter/sort/paginate → multiple display modes (page, block, feed, REST) → access-aware caching → admin-composable.

| Feature | Status | Notes |
|---|---|---|
| Query abstraction | ✅ | `EntityStorageInterface::getQuery()` |
| Filter/sort/page UI | ❌ | No equivalent. |
| Pluggable display (page, block, feed, REST) | ❌ | No equivalent. |
| Admin-composable in browser | ❌ | No equivalent. |
| Cache integration with access | ❌ | No equivalent. |

**This is the largest single feature gap.** A Drupal shop migrating to Waaseyaa rebuilds every Views display as a custom controller + template. For a community CMS, listings are 60–80% of pages. See §3.4.

### 1.5 Configuration management

| Feature | Status | Notes |
|---|---|---|
| Config entities | ✅ | `config` package, `ConfigEntityBase` |
| Active store / sync store split | ❓ | Drupal CMI separates the running config from the version-controlled export. Does Waaseyaa? |
| Config export to filesystem | ❓ | Drupal: `drush config:export`. Waaseyaa: `bin/waaseyaa config:export`? Unverified. |
| Config import from filesystem | ❓ | Same. |
| Config diff between active and sync | ❓ | |
| Config dependency graph | ❓ | Drupal computes config dependencies (config that depends on a module → uninstalling the module). |

**Without a config sync story, environment promotion (dev → staging → prod) is operationally hard.** This is a production-grade gap. See §3.5.

### 1.6 Migration

| Feature | Status | Notes |
|---|---|---|
| Source plugins (read foreign data) | 📋 | Substrate planned in core per [ADR 012a](../adr/012a-migration-substrate-in-core.md); first-party readers as packages (`waaseyaa-migrate-source-wordpress` first, `-drupal7` second). |
| Processor plugins (transform) | 📋 | Core ships essentials (`PassThrough`, `HtmlSanitizeProcessor`, `LookupProcessor`, `ConcatProcessor`, `TypeCoerceProcessor`); specialized processors ship with source-reader packages. |
| Destination plugins (write entities) | 📋 | `EntityDestination` planned in core; rides storage coordinator (ADR 010), lifecycle events (ADR 011), revisions (ADR 016). |
| Migration manifest / dependencies | 📋 | `MigrationDefinition` declarative manifest, PHP-first; per ADR 012a. |
| Rollback support | 📋 | Per-record rollback via `DestinationPluginInterface::rollback()`; best-effort, not transactional. |
| Dev/prod migration replay | 📋 | Idempotent re-runs via stable source-ID hashing and `migration_id_map` table; per ADR 012a. |
| Incremental / continuous sync | ❌ | Out of scope in v0.x; future ADR door per ADR 012a. |

Drupal `migrate` is how organizations leave Drupal 7. Resolved by [ADR 012a](../adr/012a-migration-substrate-in-core.md): substrate in core, source readers as packages, WordPress reader first-party priority for user acquisition. See §6.3.

### 1.7 Forms

| Feature | Status | Notes |
|---|---|---|
| Form definition (declarative) | ❓ | No `form` package. SSR layer handles form rendering. |
| Form validation pipeline | ❓ | App-level today. |
| AJAX form responses | ❓ | |
| File upload handling | ❓ | `media` package implies storage but the form-upload API is unclear. |
| CSRF | ❓ | Likely present in routing — needs to be confirmed as stable surface. |

### 1.8 Rendering & theming

| Feature | Status | Notes |
|---|---|---|
| Render arrays | ❌ | Waaseyaa renders directly via Twig. Position: deliberate. |
| Twig templates | ✅ | `ssr` (Layer 6) |
| Template inheritance | ✅ | Standard Twig. |
| Theme hooks (`hook_theme`) | ❌ | No equivalent. Position: deliberate. |
| Theme suggestions | ❌ | Path-based template resolution exists in apps. Framework abstraction unclear. |
| Theme as a distributable package | ❓ | Could a `waaseyaa-theme-*` package be installed via composer and override an app's templates? Worth deciding. See §3.6. |
| Asset libraries (`*.libraries.yml`) | ❌ | Apps manage their own CSS/JS. Position: deliberate (Minoo: single hand-written CSS). |
| BigPipe / streaming render | ❓ | |
| Render cache | ❓ | `cache` package exists; render-cache integration unclear. |

### 1.9 Menus, blocks, layout

| Feature | Status | Notes |
|---|---|---|
| Menu system (hierarchical links) | 🟡 | `EntityFoundationProvider` mentions menu helpers. Surface unclear. |
| Menu access integration | ❓ | |
| Breadcrumbs | ❓ | |
| Blocks (reusable content fragments) | ❌ | |
| Block placement / regions | ❌ | |
| Layout Builder (visual composer) | ❌ | |
| Contextual links | ❌ | |

For Waaseyaa apps where authors compose pages from reusable fragments, this is a gap. For Minoo (developer-composed pages) it's a non-issue. Position depends on target consumer.

### 1.10 Revisions, workflow, moderation

| Feature | Status | Notes |
|---|---|---|
| Entity revisions | ❌ | See §1.1. |
| Revision UI (compare, revert) | ❌ | |
| Workflows (states + transitions) | ❌ | |
| Content moderation (draft → review → published) | ❌ | |
| Scheduled publishing | ❌ | |
| Per-user-role transition permissions | ❌ | |

**Critical for editorial CMSs.** Minoo's Knowledge Keeper editorial flow is currently "the policy permits the edit; the edit lands." A Drupal-shop CTO building a magazine or community newsroom on Waaseyaa needs at least revisions + moderation. See §3.1.

### 1.11 Multilingual

| Feature | Status | Notes |
|---|---|---|
| Interface translation (UI strings) | ✅ | `i18n` package (Layer 0); `trans()` Twig function. |
| Content translation (per-entity, per-field) | 🟡 | `langcode` exists in schema. Per-field translatability flag, translation API, language fallback chain — unverified. |
| Config translation | ❓ | |
| Language negotiation (URL prefix, browser, session) | ❓ | |
| RTL support | ❓ | |
| Translation provider integrations | ❌ | |

**For Minoo's Anishinaabemowin localization milestone (#21), the framework surface needs to support per-field translation cleanly.** The current state is "apps handle it." Worth promoting to framework if more than one consumer.

### 1.12 Caching

| Feature | Status | Notes |
|---|---|---|
| Cache backend abstraction | ✅ | `cache` package (Layer 0) |
| Cache tags (invalidate by tag) | ❓ | |
| Cache contexts (vary by user/role/url/etc.) | ❓ | |
| Render cache integration | ❓ | |
| Page cache (anonymous) | ❓ | |
| Dynamic page cache (auth) | ❓ | |

Drupal's tag+context system is sophisticated; equivalence isn't required, but the position needs documenting.

### 1.13 Queue, cron, batch

| Feature | Status | Notes |
|---|---|---|
| Queue API | ✅ | `queue` package (Layer 0) |
| Queue workers | ❓ | |
| Cron-style scheduled tasks | ❓ | |
| Batch API (long-running operations with progress) | ❌ | |

Batch matters for migrations, mass updates, and ingestion. Not having it is fine if the position is "use external schedulers."

### 1.14 Search

| Feature | Status | Notes |
|---|---|---|
| Search abstraction | ✅ | `search` package (Layer 3) |
| Multiple backend support | ❓ | Drupal Search API: DB, Elastic, Solr, etc. |
| Faceted search | ❓ | |
| Indexed entity reference | ❓ | |
| AI / vector search | ✅ | `ai-vector` (Layer 5). **Waaseyaa exceeds Drupal here.** |

### 1.15 Media & files

| Feature | Status | Notes |
|---|---|---|
| File API | ❓ | |
| Media entity | ✅ | `media` package (Layer 2) |
| Image styles / derivatives | ❓ | |
| Responsive image | ❓ | |
| Media library UI | ❌ | |

### 1.16 Routing, controllers, paths

| Feature | Status | Notes |
|---|---|---|
| Route definitions (YAML or PHP) | ✅ | PHP-based via routing providers. Cleaner than Drupal's `*.routing.yml`. |
| Controller attribute injection | ✅ | |
| Path aliases (`/node/123` → `/about-us`) | ✅ | `path` package (Layer 2) |
| URL handling | ✅ | |
| CSRF on state-changing routes | ❓ | |
| Per-route access | ✅ | Via policies. |

### 1.17 Console / CLI

| Feature | Status | Notes |
|---|---|---|
| CLI entry point | ✅ | `bin/waaseyaa`; `cli` package (Layer 6). |
| Command discovery from providers | 🟡 | Works post-alpha.175 with `HasNativeCommandsInterface`. Silent-failure mode pre-fix is the audit's F4. |
| Site install command | ❓ | Drupal: `drush site:install`. |
| Update command (run pending migrations) | ✅ | `bin/waaseyaa migrate`. |
| Cache rebuild | ❓ | Drupal: `drush cache:rebuild`. Waaseyaa: `rm storage/framework/packages.php`? Promote to a command. |
| Generate scaffolding | ❌ | `drush generate` has no equivalent. |

### 1.18 Logging

| Feature | Status | Notes |
|---|---|---|
| Structured log layer | ✅ | Charter §4.4 names channels. |
| Multiple backends | ❓ | |
| Per-channel level | ❓ | |
| Log viewer in admin | ❌ | |

### 1.19 Mail

| Feature | Status | Notes |
|---|---|---|
| Mail abstraction | ✅ | `notification` package (Layer 3); `AuthMailer` in foundation. |
| Provider plugins | ❓ | SendGrid hardcoded today? |
| Template rendering | ❓ | |
| Per-user opt-in | ❓ | |

### 1.20 Admin & API surfaces

| Feature | Status | Notes |
|---|---|---|
| Admin UI shell | 🟡 | `admin-surface` (Layer 6). #618 blocks admin in Minoo today. |
| Admin pages for entity CRUD | ❓ | |
| Toolbar / nav | ❓ | |
| JSON API / REST | 🟡 | `api` (Layer 4); per-entity REST scaffolding unclear. |
| GraphQL | ❌ | |
| MCP server | ✅ | `mcp` (Layer 6). **Waaseyaa exceeds Drupal here.** |

### 1.21 Other Drupal-typical features

| Feature | Status | Notes |
|---|---|---|
| Comments (entity-attached) | 🟡 | Minoo has comments at app level. Framework comment entity unclear. |
| Taxonomy (vocabularies, terms, hierarchy) | ✅ | Foundation provider mentions taxonomy. |
| User/account API | ✅ | Foundation. |
| User registration / password reset | ✅ | Mailer + flows. |
| OAuth2 provider | ✅ | `oauth-provider` (Layer 1). |
| OAuth2 consumer | ❓ | |
| Update API (db updates between versions) | ✅ | `bin/waaseyaa migrate`. |
| Security advisories integration | ❌ | |
| Composer module management | ✅ | Native. Cleaner than Drupal's `composer-project-template`. |

---

## 2. Where Waaseyaa exceeds Drupal

The matrix is asymmetric on purpose — it measures against Drupal. But Drupal has gaps Waaseyaa already fills, and these are the actual differentiation. None of these were surfaced by the Laravel-snob audit.

| Capability | Surface | Drupal equivalent | Waaseyaa position |
|---|---|---|---|
| AI agents as first-class | `ai-agent` (Layer 5) | Contrib `ai_agents` | In-core, numbered layer alongside `routing`. |
| AI pipelines (RAG, summarize, classify) | `ai-pipeline` (Layer 5) | Contrib `ai` | In-core. |
| Vector storage as a framework concern | `ai-vector` (Layer 5) | Contrib `vector_*` | In-core. |
| MCP server as a framework surface | `mcp` (Layer 6) | None | In-core, novel. |
| Composer-native package manifest | `composer.json extra.waaseyaa.providers` | `*.info.yml` + `*.module` | Eliminates an entire file format. |
| Attribute-based policies | `#[PolicyAttribute]` | `hook_entity_access` × 3 | Eliminates three hooks per access check. |
| Native PHP 8.4+ types throughout | Modern attributes, readonly, enums | TypedData + annotations | Eliminates a parser. |
| No `.module` procedural files | Pure OO providers | `hook_*` at file scope | Eliminates an entire programming style. |
| Composable taxonomy contract | `jonesrussell/indigenous-taxonomy` (consumer-shared) | Each site has its own vocabulary | Cross-site shared taxonomies as a packageable artifact. |

These items belong in any positioning document that explains *why Waaseyaa exists*. They're the "what you obsolete" half of the mission promise.

---

## 3. Mission-critical gaps

Of the 🟡 / ❌ / ❓ entries in §1, these are the ones that materially block the mission promise of "obsolete Drupal." Ordered by severity for Drupal-migration consumers (not by build difficulty).

### 3.1 Revisions + content moderation

**Gap:** No entity revision API, no workflow states, no moderation transitions.

**Mission impact:** Any editorial CMS use case (which is most of Drupal's installed base) needs at minimum "draft → published" and "compare two revisions." For Minoo specifically, a Knowledge Keeper-edited Teaching needs revision history for cultural integrity reasons, not just operational ones.

**Recommendation:** Promote to a planned framework feature. Charter implication: revisions are a first-class entity concern, so the column-backed-fields work (audit M3) should design for revision storage rather than retrofit it.

### 3.2 Per-field translation

**Gap:** Schema has `langcode`. Per-field translatability flag, translation API, and language fallback chain are not visible on the framework surface.

**Mission impact:** Minoo's Anishinaabemowin localization milestone (#21) needs this. So will every other Indigenous-language consumer. Framework-level support means dialect handling stays consistent across apps (Eastern Ojibwe in Minoo, Cree in a sister app, etc.).

**Recommendation:** Plan a `field.translatable: true|false` flag in `FieldDefinition` and a `getTranslation(langcode)` method on entities. Define the fallback chain (current → site default → first-available) in a single normative document.

### 3.3 Field widgets and formatters

**Gap:** Storage is solved; the form-input side and rendered-output side are app-level Twig today.

**Mission impact:** Cross-app reuse of authoring UIs is impossible without this. Every app rewrites the same form/render code per field type. Drupal's entire authoring ecosystem (Paragraphs, Layout Builder, Webform) presupposes the widget/formatter contract.

**Recommendation:** Either commit to widget/formatter plugins as planned framework surface, or document the intentional gap explicitly: "Waaseyaa apps own their authoring UIs; the framework will never ship form-input or display plugins." Position matters more than direction.

### 3.4 Views equivalent

**Gap:** No declarative query → filtered/sorted/paginated → multi-display abstraction.

**Mission impact:** Listings are 60–80% of pages on community CMSs. A Drupal shop migrating to Waaseyaa rebuilds every Views display as a custom controller. The cost is high, and the resulting code is worse (no caching integration, no reusable filters, no admin-composability).

**Recommendation:** The hardest item on this list, the highest-leverage if delivered. Options:
- Build it (multi-year mission).
- Adopt an existing PHP query-+-display library and integrate (e.g. extend Twig with query primitives).
- Declare it out of scope; ship excellent docs for "build your own listing." Honest position; will lose some Drupal migrations.

### 3.5 Configuration management (CMI)

**Gap:** Config entities exist; active/sync store split, export/import, diff, and dependency-graph computation are unverified.

**Mission impact:** Multi-environment deployment is operationally painful without this. Most non-Minoo Waaseyaa apps will live on a dev/staging/prod pipeline.

**Recommendation:** Smaller than Views by an order of magnitude; mostly a write-out and a CLI surface. Plan as a near-term framework feature.

### 3.6 Theme as a distributable artifact

**Gap:** Apps own their templates and CSS. No `waaseyaa-theme-*` package contract.

**Mission impact:** Minoo's visual identity is currently locked to Minoo. If a sister community wants to launch their own Waaseyaa app with Minoo's design system, they fork. A theme-package contract would let design ship as composer dependencies.

**Recommendation:** Lower priority than 3.1–3.5 but worth a position. Cheap to spec, valuable when the second Waaseyaa app appears.

---

## 4. Beyond Drupal — modern PHP adoption

The mission says "PHP 8.5+ in 2026." This is a measurable, auditable claim. The matrix doesn't grade it (that's a separate audit), but it should be tracked.

| PHP 8.4+ feature | Where it should appear | Audit status |
|---|---|---|
| `readonly` classes | Value objects, field definitions, entity types after construction | Spot-checked; unaudited |
| `final readonly class` everywhere appropriate | Same | Unaudited |
| Asymmetric visibility (`public private(set)`) | Entities with controlled mutation | Unaudited |
| Property hooks (8.4) | Computed properties on entities | Unaudited |
| `\Override` attribute (8.3) | Every override of a parent method | Unaudited |
| Backed enums | Operation kinds, lifecycle states | Spot-checked; in use |
| First-class callable syntax | Service factories | Unaudited |
| `never` return type | Throwers, exit-only paths | Unaudited |
| Lazy objects (8.4) | Service container | Unaudited |
| `new` in initializers (8.1+) | Default-value field defs | Unaudited |

**Recommendation:** A separate "modern-PHP adoption audit" mission that grep-measures these across the framework. Output: a percentage adoption number per feature, per package, tracked between alpha trains. Same shape as audit M8 (gotcha decimation) — a quantitative health metric.

---

## 5. Implications for the stability charter

The charter ([stability-charter.md](stability-charter.md)) currently governs **API stability** of whatever surface exists. This matrix adds a parallel concern: **mission-completeness** of the surface. Three implications:

### 5.1 Charter §2 (surface classification) needs a "mission status" column

Each public-surface entry currently has a tier (`stable | provisional | internal`). Add a mission-status column derived from this matrix: `present | partial | planned | intentional-gap`. Apps consuming the surface should be able to see at a glance: "this is stable AND it covers the use case I'm migrating from Drupal." A stable but partial surface is a different commitment than a stable and complete one.

### 5.2 Charter §3 (beta entry criteria) should reference mission gaps

Currently beta entry requires "two clean alpha trains" and "one non-Minoo consumer." Add: **no `❌ critical-gap` items in §3 of this matrix** before beta. Reason: declaring beta with a missing Views-equivalent or no revisions API would mislead consumers about what they're getting.

### 5.3 Charter §11 open questions absorb the §6 decisions

The decisions below should be merged into the charter's "open questions before ratification" list.

---

## 6. Decisions needed (owner: maintainers)

These are the ❓ cells of the matrix promoted to explicit decision items.

### 6.1 Multiple field storage backends

Should Waaseyaa support multiple storage backends per entity type (SQL + key-value + remote), or commit to SQL-only?

- **Drupal:** multiple.
- **Implication if SQL-only:** AI-vector storage either goes through `entity-storage` (tortured fit) or lives outside it (clean, but breaks the "everything is an entity" promise).
- **Recommendation:** Multi-backend, with `ai-vector` as the proving case.

### 6.2 Entity lifecycle events

What event names fire on entity CRUD operations, and what is their stable surface?

- **Drupal:** rich hook system (`hook_entity_presave`, `hook_entity_insert`, etc.) plus event subscribers in newer versions.
- **Implication:** Without documented events, cross-cutting concerns (audit logging, cache invalidation, denormalized search index) live in app code or in custom storage subclasses.
- **Recommendation:** Specify a minimal lifecycle (`before_save`, `after_save`, `before_delete`, `after_delete`) on the stable surface; charter §4.4 already has the `entity.deprecation` channel.

### 6.3 Migration platform position — RESOLVED

**Resolved by [ADR 012a](../adr/012a-migration-substrate-in-core.md) (2026-05-11), superseding [ADR 012](../adr/012-migration-platform-out-of-scope.md).**

Direction: **substrate in core, source readers as packages.** Framework ships the Source/Process/Destination plugin contract, manifest format, CLI runner (`bin/waaseyaa import:*`), idempotency primitives, and rollback. Source readers ship as `waaseyaa-migrate-source-*` composer packages. First-party priority: **WordPress first** (highest user-acquisition value, WXR XML format), Drupal 7 second.

Reframe rationale: under parity with Drupal 12 (which ships Migrate as core) and the strategic value of migration-from-WordPress as a user-acquisition lever, the original "out of scope" verdict was reversed. Cost contained to ~3–6 months for the substrate, ~2–3 months for the WordPress reader.

### 6.4 Form abstraction

Does Waaseyaa ship form widgets, or do apps own all form rendering?

- **If apps own:** document; ship form-styling examples; commit not to add a form layer later (consumers will have built around the absence).
- **If framework ships:** the contract is large — validation, AJAX, file upload, CSRF, multi-step. Drupal Form API is bigger than its Entity API.
- **Recommendation:** Apps own. Position clearly.

### 6.5 Theme as a package

Can a `waaseyaa-theme-*` composer package override an app's templates and CSS?

- **Recommendation:** Yes, low-cost. Spec the precedence rules now even if no theme ships.

### 6.6 Views

The big one. Three plausible positions:

- **A — Build it.** Multi-year mission. Framework grows substantially.
- **B — Integrate.** Find/build a query+display library and integrate at framework level (e.g. AI-pipeline-style declarative listings).
- **C — Out of scope.** Document, ship excellent listing examples, accept that Drupal magazine-shop migrations are not the target.
- **Recommendation:** B for the next two years; revisit at v1.0.

### 6.7 Revisions

A or B:

- **A — First-class.** `RevisionableEntityInterface`, revision storage as part of M3, revision API on stable surface.
- **B — Plugin / contrib.** Framework provides hooks; revisions ship as a separable `waaseyaa-revision` package.
- **Recommendation:** A. Revisions are too entangled with storage to defer to a plugin.

---

## 7. Cross-references

- [`stability-charter.md`](stability-charter.md) — the charter this matrix informs.
- [`public-surface-map.md`](../public-surface-map.md) — substrate for the "Surface" column.
- [`VERSIONING.md`](../VERSIONING.md) — pre-v1 / v1.0 / v1.x policy.
- 2026-05-11 framework/app audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) — the Laravel-lens audit this matrix complements.

---

## 8. Update cadence

This matrix is reviewed:

- Quarterly, alongside the charter audit (§8.5 of the charter).
- When a new package adds its first package-local public-surface declaration (and therefore enters the composed surface).
- When a 📋 item ships (status moves to ✅ or 🟡).
- When a ❓ becomes ❌ via a decision (entry annotated with decision date).

Owner: framework maintainers.
