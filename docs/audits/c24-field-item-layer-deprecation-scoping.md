# C-24 — dead Field-API item layer: deprecation plan & tradeoffs (scoping)

**Status:** scope for review — DO NOT execute. Per directive: ground in current code, write the
deprecation plan + tradeoffs, report before touching code.
**Grounded against:** current `main`.

## 1. What's dead (the audit's diagnosis, re-confirmed at `file:line`)

The Drupal-lineage Field-API **item/value-object layer** is built, `@api`, and **instantiated
nowhere in production**:

- `FieldItemList` (`packages/field/src/FieldItemList.php`) and the **instance** methods of
  `FieldItemBase` (`get()`/`getProperties()` at `:61,68,87`) build `PropertyValue` objects — but
  these instance methods are called **only by each other and by tests** (`FieldItemBaseTest`,
  `FieldItemListTest`). Grep for production `new FieldItemList(...)` / `new FieldItemBase(...)` /
  `new PropertyValue(...)` outside `FieldItemBase`'s own instance methods + tests → **zero hits**.
- `validate()` is a **no-op** in both: `return new ConstraintViolationList();`
  (`FieldItemList.php:128`, `FieldItemBase.php:141`). Real validation runs through
  `EntityValidator` against raw values, never the item layer.
- The entity system reads/writes values via the `_data` blob / `$values` array
  (`ContentEntityBase`), not the item-object layer.
- **The whole `TypedData` ancestry is dead with it.** `ComplexDataInterface`/`TypedDataInterface`
  (and the rest of `waaseyaa/typed-data`) are used **only** by this dead field-item layer — grep
  for `TypedDataInterface`/`ComplexDataInterface`/`use Waaseyaa\TypedData` outside the
  `typed-data` package itself and `field/src/{FieldItem*,PropertyValue}` → **zero hits**. So the
  entire L0 `typed-data` package is unwired except through the dead layer.

## 2. Why it is NOT a clean delete — the live static seam (the entanglement)

`FieldItemBase` serves **double duty**. Alongside the dead instance layer it hosts a set of
**static** methods that are **live and load-bearing**:

- `FieldItemBase::{defaultSettings(), defaultValue(), schema(), schemaFor($def), jsonSchemaFor($def)}`
  (`:166,171,193,224,…`).
- `FieldTypeManager` calls them **statically** on the field-type class:
  `$class::defaultSettings()` (`:45`), `$class::schema()` (`:57`), `$class::jsonSchemaFor($def)`
  (`:73`), `$class::schemaFor($def)` (`:90`) — reached on the live JSON-schema / storage-column
  derivation path (`FieldDefinition`).
- **18 concrete `#[FieldType]` plugins extend `FieldItemBase`** for that static seam
  (`BooleanItem`, `DateItem`, `DecimalItem`, …, `ClassificationLabelFieldType`).

So `FieldItemBase` cannot be deleted as a unit: deleting it (or its statics) breaks live field-type
schema/column derivation for every entity type. Only the **instance** half (the item-object
methods, `FieldItemList`, `PropertyValue`, the ComplexData/TypedData *instance* contract) is dead.

## 3. Published `@api` surface (the BC cost)

In `docs/public-surface-map.php`, marked `public`:

| Symbol | Map line | Live or dead? |
|---|---|---|
| `Waaseyaa\Field\FieldTypeInterface` | 317 | **LIVE** — the static-seam contract the 18 plugins satisfy. **Stays.** |
| `Waaseyaa\Field\FieldItemBase` | 320 | **Mixed** — dead instance methods + live statics. Reshaped, not deleted. |
| `Waaseyaa\Field\FieldItemInterface` | 313 | DEAD instance contract. |
| `Waaseyaa\Field\FieldItemListInterface` | 314 | DEAD instance contract. |
| `Waaseyaa\TypedData\{TypedDataInterface, ComplexDataInterface, ListInterface, PrimitiveInterface, DataDefinitionInterface, TypedDataManagerInterface, …}` | 97–105 | DEAD (used only by the dead layer). |

`Waaseyaa\Field\ComputedFieldInterface` (331) is `internal` (one live field type implements it via
`ComputedItem`) — verify before touching.

**BC posture:** these are published-surface commitments that normally need a charter §4 deprecation
cycle. **But the framework is pre-1.0 (`0.1.0-alpha.*`):** per the charter's pre-1.0 stance we may
**break rather than shim** — the right move here, since these are dead symbols no real consumer can
be using for their stated (instance-layer) purpose. **No BC shims.** The change is: remove the
symbols + their surface-map entries in the same train, with a CHANGELOG `### Removed` entry and an
`UPGRADING.md` note, rather than a deprecation-window stub.

## 4. The plan — de-entangle first, then delete, across 3 trains

**Train 1 — extract the static seam off the instance class (behaviour-preserving).**
Move the live statics (`defaultSettings`/`defaultValue`/`schema`/`schemaFor`/`jsonSchemaFor`) off
`FieldItemBase` into a non-instance host — a new `AbstractFieldType` base (or a `FieldTypeTrait` +
the existing `FieldTypeInterface`) that the 18 `#[FieldType]` plugins extend/use instead of
`FieldItemBase`. Repoint `FieldTypeManager`'s `$class::…()` calls (no signature change). Net effect:
field-type plugins no longer carry the dead instance layer; the static seam is unchanged.
*Acceptance:* the 18 `Item/*Test` static-seam assertions stay green; the JSON-schema/column
derivation byte-identical. No surface-map change yet (`FieldItemBase` still exists, now instance-only).

**Train 2 — delete the dead instance layer.**
Remove `FieldItemList`, `PropertyValue`, and `FieldItemBase`'s instance methods (the now-empty
shell of `FieldItemBase` is deleted; the field types already moved off it in Train 1). Delete
`FieldItemBaseTest`/`FieldItemListTest` and excise the dead-instance assertions from the 18
`Item/*Test` files (keep their static-seam assertions). Drop the `FieldItemInterface`,
`FieldItemListInterface`, `FieldItemBase` entries from the surface map. `### Removed` CHANGELOG +
`UPGRADING.md`.

**Train 3 — retire the now-fully-dead `typed-data` ancestry.**
With the item layer gone, `waaseyaa/typed-data` has no consumers. Remove the package (or, if a
future use is intended, demote its interfaces to `internal` and document them as a parked
substrate). Drop the TypedData surface-map entries. This is the largest surface break (6+ published
interfaces) but they are provably unused. Could also be folded into Train 2 if reviewers prefer one
break.

## 5. Test / rewiring scope
- **~20 field test files:** `FieldItemBaseTest`, `FieldItemListTest` (delete), + 18 `Item/*Test`
  (rewire: drop dead-instance assertions, keep static-seam).
- **`FieldTypeManager`** call sites (4) + the 18 plugin parent classes (mechanical reparent).
- **Surface map** (`public-surface-map.php`) + the `PublicSurfaceVerificationTest`/`surface-parity`
  gate (the removed symbols must leave the map together, or the gate flags untracked removal).
- **`typed-data` package** removal (Train 3): composer graph, `split.yml`, the layer table.
- The `@api`/dead-code gate (`check-dead-code`) currently stays green *because* `@api` shields the
  dead layer; removing the layer should keep it green (fewer symbols), but verify the baseline.

## 6. Risk & recommendation
- **Risk: MEDIUM** for Trains 1–2 (touches every field type's parent class + the schema-derivation
  seam — but behaviour-preserving and well-tested), **MEDIUM-HIGH** for Train 3 (largest published
  surface break, though provably unused). Not WP17-grade clean-cut dead code precisely because of
  the static-seam entanglement.
- **Recommendation:** execute as the 3 trains above, Train 1 first (de-risks everything by
  separating live from dead before any deletion). Confirm `ComputedFieldInterface`/`ComputedItem`
  handling and whether Train 3 folds into Train 2 before starting. Pre-1.0 → break, don't shim.
- **Out of scope here:** this is the field item layer (audit C-24). The fact that `typed-data` is
  entirely dead is surfaced as Train 3, but if the team wants `typed-data` kept as a deliberate
  future substrate, Trains 1–2 stand alone and Train 3 becomes "demote to internal + document."
