# Work Package Prompt: WP03 — ai-schema activation

**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** none. Executes in parallel with WP01 and WP02.

## CRITICAL — work in the lane worktree

`documentation` execution mode. Three files touched: `docs/specs/ai-schema.md` (new), `packages/ai-schema/README.md` (one-line addition), `CLAUDE.md` (one orchestration row update). No code changes.

## What you are doing

`ai-schema` is currently an alpha package with one class (`EntityJsonSchemaGenerator`) and no spec. AI capability work needs a registry for AI-tool input/output schemas. WP03 activates the package by authoring a spec stub that:
1. Documents the shipped surface (`EntityJsonSchemaGenerator`).
2. Sketches the capability-registry contract a future mission will implement.
3. Surfaces the spec in CLAUDE.md so future agents pick it up.

## THE pattern to mirror (read first)

- `docs/specs/ai-integration.md` — sibling AI spec; for tone, cross-reference cadence.
- `docs/specs/admin-spa.md` or `docs/specs/api-layer.md` — for spec structure (Purpose / Layer / Current surface / Future / Cross-references / Gotchas).
- `packages/ai-schema/src/EntityJsonSchemaGenerator.php` — the shipped class you document.
- CLAUDE.md orchestration table — find the existing `packages/ai-*/*` row.

## Subtasks

### T018 — Author `docs/specs/ai-schema.md`

File does not exist. Create it. Required sections (in order, with the content shape specified in `plan.md` §"Author `docs/specs/ai-schema.md`"):

1. **Title + status banner** — H1, Layer, Status (alpha).
2. **Purpose** — two-clause statement: shipped JSON Schema generation + future capability registry.
3. **Layer + position** — L5 (AI), downward deps on `entity`, upward consumers `ai-pipeline` / `ai-agent` / `ai-tools` / `mcp`.
4. **Current surface** — document `EntityJsonSchemaGenerator`: constructor takes `EntityTypeManagerInterface`; `generate(string $entityTypeId): array` returns JSON Schema draft-2020-12; behaviour: maps entity keys (id, uuid, label, bundle, langcode, revision) to JSON Schema properties; required fields enumerated.
5. **Future capability-registry contract (sketched)** — three subsections:
   - Input-schema declaration (prose only — no PHP signatures).
   - Output-schema validation (prose only).
   - Capability declaration (prose only; mention the decision space: attribute vs interface vs service-tag vs `extra.waaseyaa.*` manifest entry).
6. **Cross-references** — bullet list pointing at `ai-pipeline`, `ai-agent`, `ai-tools`, `mcp`, `docs/specs/ai-integration.md`.
7. **Gotchas** — reserve as an empty section header for the implementing mission to populate ("_To be populated by the capability-registry implementing mission._").

Length envelope: 120–250 lines (FR-013). The contract above is the skeleton; the implementer fills prose paragraphs to hit the floor without padding.

### T019 — Update `packages/ai-schema/README.md`

Current content is a short Layer-5 description. Append (do not replace; the existing README stays brief):

```markdown
See [docs/specs/ai-schema.md](../../docs/specs/ai-schema.md) for the package
contract, including the capability-registry sketch.
```

### T020 — CLAUDE.md orchestration row

Locate the existing row:

```
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md`, `docs/specs/authoring-assist-contract.md`, `docs/specs/semantic-refresh-trigger-contract.md` |
```

Insert `docs/specs/ai-schema.md` into the cold-memory column. Final shape:

```
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md`, `docs/specs/ai-schema.md`, `docs/specs/authoring-assist-contract.md`, `docs/specs/semantic-refresh-trigger-contract.md` |
```

### T021 — Verification

```bash
test -f docs/specs/ai-schema.md
wc -l docs/specs/ai-schema.md                                # 120–250
grep "ai-schema.md" CLAUDE.md | wc -l                        # >= 1
grep "EntityJsonSchemaGenerator" docs/specs/ai-schema.md     # >= 1
grep "docs/specs/ai-schema.md" packages/ai-schema/README.md  # >= 1
composer check-composer-policy
bin/check-package-layers
```

All must succeed.

### T022 — Commit + PR

Commit:
```
docs(ai-schema): activate package with spec stub + capability-registry contract sketch

Authors docs/specs/ai-schema.md documenting the shipped
EntityJsonSchemaGenerator and sketching the capability-registry contract a
future mission will implement. Surfaces the spec in CLAUDE.md orchestration.

Refs: empty-package-decisions-analytics-billing-aischema-01KSEFV4 (WP03)
```

PR title:
```
docs(ai-schema): activate package with spec stub + capability-registry contract sketch
```

PR body cites: this mission slug, the alpha-to-beta plan AI capability work, sibling WPs (WP01 analytics→audit, WP02 billing), `docs/specs/ai-integration.md` as the larger AI integration spec.

## Verification gate (in lane worktree)

- T021 all green.
- `git status` shows only `docs/specs/ai-schema.md` (new), `packages/ai-schema/README.md` (modified), `CLAUDE.md` (modified).

## Commit + handoff

Open PR; cross-reference WP01 and WP02 PRs.

## Report back with

- Final spec line count.
- PR URL.
- Any unresolved questions about the contract sketch (these become the future implementing mission's input).

## Activity Log

_(populated during execution)_
