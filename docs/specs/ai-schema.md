# ai-schema

<!-- Spec reviewed 2026-09-01 - #2786: EntityJsonSchemaGenerator is a principal-bound adapter over the lower-layer FieldSchemaAuthority. It enumerates effective fields, emits a closed schema, and cannot run without an entity subject, access handler, and account. The package still has no runtime consumer. -->

**Layer:** 5 — AI
**Status:** alpha

## Purpose and boundary

`waaseyaa/ai-schema` contains one optional AI-facing adapter. It does not own
field mappings, application-plan contracts, tool schemas, or a capability
registry. The canonical structural authority is
`Waaseyaa\Field\FieldSchemaAuthority` in the Layer-1 field package.

The package depends downward on `waaseyaa/access`, `waaseyaa/entity`, and
`waaseyaa/field`. There is no current runtime consumer. `ai-agent`, `ai-pipeline`,
`ai-tools`, and `mcp` neither require nor import it. Do not add a consumer edge
merely to make this utility appear wired.

## `EntityJsonSchemaGenerator`

**Namespace:** `Waaseyaa\AI\Schema`
**Marked:** `@api`

```php
constructor(
    EntityTypeManagerInterface $entityTypeManager,
    ?FieldSchemaAuthority $fieldSchemas = null,
)

generate(
    string $entityTypeId,
    EntityInterface $entity,
    EntityAccessHandler $accessHandler,
    AuthorizationPrincipalInterface $account,
): array
```

`generate()` resolves the registry- and bundle-aware effective field set via
`EntityTypeManagerInterface::resolveFieldDefinitions()`, requires explicit
entity `view` access, removes fields explicitly forbidden for `view`, and then
delegates all structural emission to `FieldSchemaAuthority`. A subject whose
entity type differs from the requested type is rejected.

The returned draft-2020-12 schema:

- enumerates entity keys and every visible effective field;
- includes field type, cardinality, required/read-only state,
  translation/revision flags, safe constraints, and plugin-owned value shape;
- uses `additionalProperties: false`; and
- fails closed for an unregistered field type.

There is deliberately no unscoped `generateAll()` method. A global field
catalogue without a principal and matching subject would turn protected field
metadata into an unauthorized introspection surface.

## Projection ownership

Field plugins own two deliberately distinct schemas:

- `jsonSchemaFor()` describes the field-item/storage value object; and
- `entityValueJsonSchemaFor()` describes the value accepted or exposed by an
  entity authoring surface.

`FieldSchemaAuthority` uses the entity-value projection. This distinction is
required for types such as `text`: its item has `value` and `format` members,
while current entity authoring and JSON:API surfaces carry a string. API and AI
adapters may decorate the canonical entity schema, but must not maintain a
parallel field-type-to-JSON-Schema table.

## Non-goals

- MCP resources or automatic tool registration;
- provider-specific schema dialects;
- a Studio-owned application-plan schema;
- a future capability registry; or
- GraphQL scalar/object mapping, which is a separate wire-type adapter rather
  than JSON Schema authority.

## Cross-references

- `docs/specs/entity-system.md` — field plugin and effective-field authority
- `docs/specs/api-layer.md` — authorized admin-schema decoration
- `docs/specs/site-golden-path.md` — Layer-0 blueprint vocabulary conformance
- `docs/specs/ai-integration.md` — broader AI architecture
