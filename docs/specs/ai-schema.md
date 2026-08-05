# ai-schema

<!-- Spec reviewed 2026-08-04 - #2200: the shipped package contains only EntityJsonSchemaGenerator and has no current runtime consumer outside its own tests. ai-agent, ai-pipeline, ai-tools, and mcp do not require or import it; capability-registry and MCP-resource integration remain future design, not shipped behavior. -->

**Layer:** 5 — AI
**Status:** alpha

> This spec documents the shipped surface and sketches the capability-registry contract a future mission will implement.

---

## Purpose

The `waaseyaa/ai-schema` package serves two purposes:

1. **Shipped:** Derives JSON Schema (draft 2020-12) from Waaseyaa entity type definitions, giving AI agents and pipelines a typed, standards-compliant description of the framework's data model.
2. **Future:** Acts as the registry for AI tool input/output schemas — the "capability-registry" contract — so that AI pipeline and agent layers can declare and validate the structured data they exchange with language models.

---

## Layer and position

`ai-schema` sits at Layer 5 (AI). It depends downward on:

- `waaseyaa/entity` (L1) — `EntityTypeManagerInterface`, `EntityTypeInterface`

There is no current runtime consumer. The root and `waaseyaa/full`
distributions ship the utility explicitly, while its own tests exercise the
public generator. `ai-agent`, `ai-pipeline`, `ai-tools`, and `mcp` neither
require nor import it. The Layer-5/Layer-6 integrations below are future design
space and must not be read as active dependency edges or advertised resources.

---

## Current surface

### `EntityJsonSchemaGenerator`

**Namespace:** `Waaseyaa\AI\Schema`
**Marked:** `@api`

```
constructor(EntityTypeManagerInterface $entityTypeManager)
```

```
generate(string $entityTypeId): array<string, mixed>
generateAll(): array<string, array<string, mixed>>
```

**Behaviour of `generate()`:**

Calls `EntityTypeManagerInterface::getDefinition($entityTypeId)` to retrieve the entity type. Reads the entity key map (`getKeys()`) and label (`getLabel()`). Produces a JSON Schema draft-2020-12 object with the following structure:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "<entity label>",
  "description": "Schema for <label> entities",
  "type": "object",
  "properties": { ... },
  "required": [ ... ],
  "additionalProperties": true
}
```

**Property mapping (entity key → JSON Schema property):**

| Entity key | JSON Schema type | format | required? |
|---|---|---|---|
| `id` | `["integer", "string"]` | — | yes |
| `uuid` | `"string"` | `"uuid"` | yes |
| `label` | `"string"` | — | yes |
| `bundle` | `"string"` | — | yes |
| `langcode` | `"string"` | — | no |
| `revision` | `"integer"` | — | yes (only if entity type `isRevisionable()`) |

Keys not present in `getKeys()` are omitted silently. `additionalProperties` is always `true` — the schema describes known structural keys; field-level properties are not yet enumerated (forthcoming capability-registry work).

**`generateAll()`:** Iterates `EntityTypeManagerInterface::getDefinitions()` and calls `generate()` for each. Returns a map keyed by entity type ID.

---

## Future capability-registry contract (sketched)

The following subsections describe the design space for a future implementing mission. No PHP signatures are specified here — that is the implementing mission's responsibility.

### Input-schema declaration

AI tools (MCP tools, agent actions, pipeline steps) need a way to declare the JSON Schema describing their expected input parameters. The capability registry should provide a surface for a tool class (or its metadata) to publish an input schema that is both machine-readable and cacheable. The schema should be derivable from either the entity system (via `EntityJsonSchemaGenerator`) or from a custom definition authored by the tool implementer. The registry must not require a live entity type to exist — some tool inputs are not entity-shaped.

### Output-schema validation

When a language model returns a structured response (tool call result, JSON mode output), the pipeline layer needs to validate it against a declared output schema before hydrating domain objects. The capability registry should provide a validation entry point that accepts an LLM response payload and a schema reference, returning a typed result or a structured validation error. The validation layer must not throw on schema mismatch — callers need the error details to retry or escalate.

### Capability declaration

The decision space for how tool classes declare their schemas includes:

- **PHP attribute** on the tool class (e.g., `#[ToolSchema(input: InputDto::class, output: OutputDto::class)]`) — discoverable at compile time via `PackageManifestCompiler`.
- **Interface** (`SchemaAwareToolInterface`) — discoverable at runtime via `instanceof`; no manifest needed.
- **Service tag** in the container (`ai_schema.capability`) — consistent with the framework's tagged-collection patterns; resolved lazily.
- **`extra.waaseyaa.*` manifest entry** — zero-dependency declaration in `composer.json`; usable by packages that cannot depend on `ai-schema` at runtime.

The implementing mission must record the chosen strategy as an ADR and update this section.

---

## Cross-references

- `waaseyaa/ai-tools` — owns current per-tool schemas and validation without an ai-schema dependency
- `waaseyaa/ai-agent` — executes declared tools without consuming entity-schema generation
- `waaseyaa/mcp` — transports ai-tools descriptors; no ai-schema resources are advertised
- `docs/specs/ai-integration.md` — broader AI integration architecture and current dependency truth

---

## Gotchas

- Do not add a consumer edge merely to make the future capability-registry
  sketch appear wired. The first real integration must own an executable
  contract and update the package graph deliberately.
