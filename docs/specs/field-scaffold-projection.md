# Canonical field scaffold projection

Change record: `docs/change-records/FW-FIELD-PROJECTION-01.md` (#2847).

## Purpose

Field declarations emitted by manual scaffolds must derive their admissible
type ids, PHP property types, defaults, entity-value shape, and storage shape
from the registered field authority. A generator must not carry a second
field-type-id map.

## Authority and projection

`Waaseyaa\Field\FieldScaffoldProjection` is the internal field-package adapter
for PHP scaffold emitters. It consumes one
`FieldTypeManagerInterface&FieldValueKindResolverInterface` and:

- admits registered blueprint-capable field types whose default settings are
  complete;
- additionally admits registered entity-reference value kinds for authored
  manual references;
- validates every projected definition through `FieldSchemaAuthority` and the
  plugin-owned entity storage projection;
- selects a scalar PHP representation from `FieldValueKind`, retaining it only
  when `FieldTypeInferrer::isCompatible()` permits the explicit field id;
- falls back to `mixed = null` when a registered field has no compatible
  scalar declaration; and
- projects multiple-value definitions as `array = []`.

Unknown ids and registered types lacking required declaration metadata fail
closed. The adapter has no field-type-id allowlist and does not widen
`FieldTypeInterface`.

## Manual and blueprint semantics

For equivalent scalar declarations, manual `make:content-type` and blueprint
entity emission use the same property representation. In particular:

- `text` projects as `string = ''`; and
- `datetime` projects as `mixed = null`, because a scalar `string` declaration
  is not compatible with the explicit `datetime` field id.

The blueprint emitter and its golden artifacts are unchanged by this slice.

Entity references are intentionally not equivalent inputs. A manual author may
write `field:entity_reference:target`; the generated `#[Field]` keeps
`settings.target_entity_type_id`. A blueprint author declares a relationship,
which synthesizes the reference field and also emits relationship-registry
metadata such as cardinality, requiredness, and deletion behavior. The scalar
blueprint vocabulary therefore continues to exclude `entity_reference`.

## Integration boundary

The projection accepts a boot-scoped registered manager so downstream field
plugins can participate without a generator edit. Wiring the command provider
to inject the kernel's boot-scoped manager, and the remaining packaged
create/read/update acceptance for #2847, are separate integration work. This
slice does not edit the command provider, blueprint compiler/emitter, shared
golden fixtures, generation engine, or public-surface aggregates.

## Rendering and label custody

Registry admission does not make a type id safe to interpolate as a PHP literal.
Emit registered ids with PHP literal escaping and preserve their exact value.
The manual label key remains the first authored `string` field, falling back to
the first field; changing a field's PHP representation must not change that key.
