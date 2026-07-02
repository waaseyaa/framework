# Work Surface

## Overview

The **Single-Entity Work Surface** is a set of six framework primitives that downstream applications use to build per-entity editing workspaces. Each primitive is independent and composable; they do not require each other at runtime.

| # | Primitive | Package | Layer |
|---|---|---|---|
| F1 | Deep-link route builder | `waaseyaa/routing` | L4 |
| F2 | Bundle template compiler | `waaseyaa/field` | L1 |
| F3 | Per-field auto-save endpoint | `waaseyaa/api` | L4 |
| F4 | Attachment repository | `waaseyaa/attachment` | L2 |
| F5 | Structured importer | `waaseyaa/structured-import` | L3 |
| F6 | Form descriptor builder | `waaseyaa/field` | L1 |

For a hands-on walkthrough see `kitty-specs/single-entity-work-surface-01KQ7M1P/quickstart.md`.

---

## F1 — EntityDeepLinkRouteBuilder

Produces a Symfony `Route` at `{segment}/{entityType}/{id}` wired for entity upcasting.

```php
use Waaseyaa\Routing\EntityDeepLinkRouteBuilder;

$route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
    ->controller(NodeEditController::class . '::view')
    ->requireAuthentication()
    ->build();
// Path: GET /edit/node/{id}
// options.parameters.id.type = 'entity:node'
```

**Key contract**: the `id` parameter option carries `type: 'entity:node'` for upcasting by `EntityParamConverter`. The builder delegates to `RouteBuilder::create()` so all `RouteBuilder` options are chainable after `->controller(...)`.

→ See `docs/specs/api-layer.md` for the full route catalog.

---

## F2 — BundleTemplate / FieldTemplate + BundleTemplateCompiler

Declare bundle-scoped field definitions directly on PHP classes using attributes.

```php
use Waaseyaa\Field\Attribute\BundleTemplate;
use Waaseyaa\Field\Attribute\FieldTemplate;

#[BundleTemplate(entityType: 'node', bundle: 'profile')]
final class ProfileTemplate
{
    #[FieldTemplate(key: 'name', type: 'string', label: 'Display Name',
        group: 'identity', promptAliases: ['name', 'display name'], required: true)]
    public string $name = '';

    #[FieldTemplate(key: 'bio', type: 'text', label: 'Biography', group: 'about',
        promptAliases: ['bio', 'biography'])]
    public string $bio = '';
}
```

**Compile at boot**:

```php
use Waaseyaa\Field\BundleTemplateCompiler;

$compiler = new BundleTemplateCompiler($fieldDefinitionRegistry);
$compiler->compile([ProfileTemplate::class]); // idempotent
```

**Uniqueness invariants**: duplicate field key or normalized prompt alias within a bundle throws `\InvalidArgumentException`. Normalization is UTF-8 lowercase + whitespace collapse — no transliteration.

→ See `docs/specs/entity-system.md` § "Field templates and the bundle registry".

---

## F3 — FieldAutoSaveController

Per-field PUT endpoint registered under `PUT /api/{entityType}/{id}/field/{key}`.

```
Content-Type: application/json
{"value": "<string>"}
```

**Status codes** (per contracts/README.md F3):

| Code | Condition |
|------|-----------|
| 200 | Field saved |
| 401 | No `_account` on request |
| 403 | Entity-level or field-level access denied |
| 404 | Unknown entity type, entity not found, or field not registered |
| 415 | Content-Type is not `application/json` |
| 422 | Body too large (> 65 536 bytes), malformed JSON, or missing `value` key |

**Constructor**:
```php
new FieldAutoSaveController(
    entityTypeManager: $entityTypeManager,
    accessHandler: $accessHandler,
    fieldRegistry: $fieldRegistry,
    maxBodyBytes: 65536,  // optional, default 65 536
)
```

**Performance budget (NFR-001)**: p95 ≤ 50 ms under typical load.

→ See `docs/specs/api-layer.md` for route wiring.

---

## F4 — Attachment + AttachmentRepository

`Attachment` is a Layer-2 content entity linked to a parent entity. `AttachmentRepository` enforces the **at-most-one-active invariant** via a two-UPDATE transaction.

```php
// Save three attachments.
foreach (range(1, 3) as $i) {
    $a = new Attachment(['parent_entity_type' => 'node',
        'parent_entity_id' => $nodeId, 'filename' => "file{$i}.pdf"]);
    $a->enforceIsNew();
    $repo->save($a);
}

// Atomically promote second attachment; all siblings cleared.
$repo->setActive($id2);

// Returns the single active attachment, or null.
$active = $repo->getActive('node', $nodeId);
```

**Access**: `ParentDelegatedAccessPolicy` delegates view/update/delete decisions to the parent entity's registered policy. Orphaned attachments (no parent) return `AccessResult::neutral()` which denies under entity-level `isAllowed()` semantics.

→ See `docs/specs/access-control.md` § "Parent-delegated policies".

### At-most-one-active invariant: enforcement surfaces

The invariant — at most one `Attachment` row has `is_active = 1` per `(parent_entity_type, parent_entity_id)` — has FOUR write/read surfaces (audit-remediation batch 2026-07-02, WP2). `AttachmentActiveInvariant` (`packages/attachment/src/AttachmentActiveInvariant.php`) centralizes the shared "demote every sibling, then persist" semantics so the two write-path surfaces below share one implementation:

1. **`AttachmentRepository::setActive($id)`** — the original mechanism: two `UPDATE`s (`SET is_active = 0` on all siblings, then `SET is_active = 1` on the target) in one `$database->transaction()`. Fully atomic on a single connection.
2. **`AttachmentRepository::save($attachment)`** — when the saved entity's `is_active` is truthy, `AttachmentActiveInvariant::demoteSiblings()` and `$entityRepository->save($attachment)` run inside the SAME transaction, mirroring `setActive()`'s semantics. `EntityRepository::save()` opens its own transaction internally, but Doctrine DBAL nests `beginTransaction()`/`commit()` by reference-count on a single connection (no savepoint needed), so the inner transaction folds into the outer one — the demote + write is atomic. Inactive saves take the original no-guard path unchanged.
3. **Generic entity API (`getRepository('attachment')->save()`)** — bypasses `AttachmentRepository` entirely, so it needs its own enforcement point: `AttachmentActiveGuardListener`, registered by `AttachmentServiceProvider::boot()` on `EntityEvents::PRE_SAVE`, applies the same demote-siblings-first logic when the saved `Attachment`'s `is_active` is truthy. Wiring follows the pattern `RelationshipServiceProvider::boot()` established for its delete guard (#1852): the kernel-services bus serves the event dispatcher only under `\Symfony\Contracts\EventDispatcher\EventDispatcherInterface`, and resolving the foundation FQCN instead is a silent no-op — `boot()` resolves the Symfony-contracts key, then type-checks against the foundation contract.
   - **Residual race (honest, not closed by this listener alone)**: `EntityRepository::save()` dispatches `PRE_SAVE` OUTSIDE its write transaction — the event fires before the internal `$this->database?->transaction()` opens (`EntityRepository::doSave()`). The listener's demote `UPDATE` therefore commits separately from the subsequent `INSERT`/`UPDATE` of the target row. Two processes racing the interleaving demote(P1)→demote(P2, no-op)→insert(P1)→insert(P2) can both "win", leaving two active rows — on a platform with no backstop (surface 4 below unavailable).
4. **Partial unique index (backstop, not primary mechanism)** — `AttachmentSchema::ensureActivePartialUniqueIndex()` materializes `CREATE UNIQUE INDEX attachment_one_active_per_parent ON attachment(parent_entity_type, parent_entity_id) WHERE is_active = 1` on platforms with partial-index support (SQLite ≥3.8, PostgreSQL ≥9.0 — mirrors the platform-detection/quoting pattern already used by `SqlSchemaHandler::ensureSqlBlobTranslatablePartialUuidIndex()`). Where materialized, this closes the residual race in (3): the losing writer's `INSERT`/`UPDATE` fails loudly with `UniqueConstraintViolationException` instead of silently succeeding. On MySQL/MariaDB (no partial-index support at all — the framework's `SchemaInterface::addIndex()` has no `WHERE`-clause support either) this is a no-op with a logged `warning()`; the invariant on those platforms rests on surfaces 1–3 plus detection (below). Wrapped in try/catch (unlike its precedent) because it is optional hardening — a pre-existing install whose data already violates the invariant, or an unrecognized platform, must not block `ensureTable()`.

`AttachmentRepository::getActive()` is the read-side detection backstop: it orders `['id' => 'DESC']` (newest wins) and fetches `limit: 2` instead of an unordered `LIMIT 1`. When 2 rows come back — the invariant has been violated by a path outside this repository's control — it logs an ERROR via the repo-convention `Waaseyaa\Foundation\Log\LoggerInterface` (constructor-injected, defaults to `NullLogger`) naming the parent and both ids, and still returns the deterministic winner rather than throwing.

**Bottom line**: the invariant is fully atomic and portable via surfaces 1–2 (the direct repository path). Surface 3 (generic entity API) is fully atomic too on SQLite/PostgreSQL once surface 4's index exists; on MySQL/MariaDB, or an install where index creation was skipped because pre-existing data already violated the invariant, surface 3 has a genuine (if narrow) cross-process race, closed only by detection, not prevention. This is a deliberate, documented residual — not a claim of full atomicity everywhere.

**Download response filename**: `AttachmentDownloadRouter` emits a dual `Content-Disposition` filename per RFC 5987/6266 — `attachment; filename="<ascii-safe>"; filename*=UTF-8''<percent-encoded>`. The quoted `filename=` stays the existing ASCII-sanitized fallback (`[^A-Za-z0-9._-]` → `_`, so non-ASCII names degrade to underscores there); `filename*` carries the original UTF-8 filename `rawurlencode()`-percent-encoded, so RFC 6266-aware clients render Anishinaabemowin and other non-ASCII filenames intact. `filename*` is omitted (ASCII fallback only) when the stored filename is not valid UTF-8 or has an empty basename. Hardening: directional-formatting characters (U+202E RTLO, LRM/RLM/ALM, isolate controls) are stripped from `filename*` before encoding (extension-spoofing; ZWJ/ZWNJ kept — orthographically meaningful), and both parameters cap at 255 characters (header-bloat guard; shorter names untouched).

---

## F5 — StructuredImporterInterface / GfmTableImporter

Parse structured text (currently GFM 2-column tables) into matched/unmatched field values using the field registry's prompt aliases.

```php
use Waaseyaa\StructuredImport\Gfm\GfmTableImporter;
use Waaseyaa\StructuredImport\Gfm\GfmTableParser;
use Waaseyaa\StructuredImport\Gfm\PromptNormalizer;

$importer = new GfmTableImporter($fieldRegistry, new GfmTableParser(), new PromptNormalizer());

$result = $importer->import($payload, 'node', 'profile');
// $result->matched   — array<field-key, raw-value>
// $result->unmatched — list<UnmatchedRow{prompt, value}>
// $result->errors    — list<string> (parser errors)
```

**Matching rules**: field name is an implicit alias; declared `promptAliases` take precedence. Normalization is UTF-8 lowercase + whitespace collapse (no transliteration — C-012). `bundle` defaults to `entityTypeId` when `null`.

**Performance budget (NFR-004)**: peak memory ≤ 2× payload size.

---

## F6 — FormDescriptorBuilder

Produces an ordered list of `FormFieldDescriptor` value objects for a bundle. No HTML, no rendering — pure value objects for the template or SPA layer.

```php
use Waaseyaa\Field\Form\FormDescriptorBuilder;

$builder = new FormDescriptorBuilder(
    registry: $fieldRegistry,
    accessHandler: $accessHandler,  // optional
);

$descriptors = $builder->build($entity, 'profile', $account); // account optional
```

Each `FormFieldDescriptor` carries: `name`, `type`, `label`, `group`, `value` (raw from `EntityInterface::get()`), `readOnly`, `required`, `errors`.

When `accessHandler` + `account` are both provided, fields whose `update` access returns `Forbidden` are upgraded to `readOnly = true` (open-by-default field semantics — Neutral leaves the field editable).

**Performance budget (NFR-003)**: compiler ≤ 5 ms for ≤ 100 fields.

---

## Wire-up Reference

Minimum wire-up for all six primitives in a service provider `boot()`:

```php
// F2: compile bundle templates
$compiler = new BundleTemplateCompiler($this->resolve(FieldDefinitionRegistryInterface::class));
$compiler->compile([ProfileTemplate::class]);

// F1: register deep-link routes
$router = $this->resolve(WaaseyaaRouter::class);
$router->addRoute('edit.node', EntityDeepLinkRouteBuilder::for('/edit', 'node')
    ->controller(NodeEditController::class . '::view')
    ->requireAuthentication()
    ->build());

// F3: route already registered by FieldServiceProvider via api package conventions
//     PUT /api/{entityType}/{id}/field/{key} → FieldAutoSaveController::update

// F4: attachment repo injected from container (AttachmentServiceProvider registers it)

// F5: importer injected from container (StructuredImportServiceProvider registers it)

// F6: builder injected from container or constructed inline
$builder = new FormDescriptorBuilder($this->resolve(FieldDefinitionRegistryInterface::class));
```

---

## Security

### Parent-delegated policy semantics

`ParentDelegatedAccessPolicy` returns `AccessResult::neutral()` (not `forbidden()`) when the parent cannot be found. Under entity-level access semantics (`isAllowed()` — deny unless granted), neutral effectively denies without encoding an explicit decision. This prevents orphaned attachments from becoming publicly accessible.

### Body size cap (NFR-002)

`FieldAutoSaveController` enforces a 65 536-byte cap on the request body:
1. `Content-Length` header is checked first (fast path — no body read).
2. After `getContent()`, the raw body size is re-checked.

Chunked transfer without `Content-Length` falls through to step 2.

---

## Performance Budgets

| Ref | Primitive | Budget |
|-----|-----------|--------|
| NFR-001 | FieldAutoSaveController::update() | p95 ≤ 50 ms |
| NFR-003 | BundleTemplateCompiler::compile() | ≤ 5 ms for ≤ 100 fields |
| NFR-004 | GfmTableImporter::import() | peak memory ≤ 2× payload |

---

## Cross-references

- `docs/specs/entity-system.md` — field templates and bundle registry
- `docs/specs/api-layer.md` — F3 route catalog entry; status code matrix
- `docs/specs/access-control.md` — parent-delegated policy pattern
- `docs/specs/field-access.md` — field-level access semantics (open-by-default)

---

> **DIR-003**: No compatibility shims, no `@deprecated` annotations, no `Legacy*` classes.
> Downstream callers that relied on any removed API must update in-place.
> See `.kittify/charter/charter.md` § DIR-003 (Greenfield Removal Policy).
