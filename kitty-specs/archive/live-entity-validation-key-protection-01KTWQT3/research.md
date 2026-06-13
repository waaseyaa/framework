# Research & Decisions: Live Entity Validation & Key Protection

**Date**: 2026-06-12 | **Plan**: [plan.md](plan.md)

All findings verified against source at current `main` (post-alpha.203). No outstanding `[NEEDS CLARIFICATION]` items.

## Verified ground truth

- `EntityRepository::__construct()` takes `?EntityValidator $validator = null` (`packages/entity-storage/src/EntityRepository.php:60`); `doSave()` validates only when non-null and throws `EntityValidationException` on violations (`:361-372`). A per-save `bool $validate = true` opt-out already exists on `save()` and `saveMany()`.
- The kernel repository factory constructs `EntityRepository` without the `validator:` argument (`packages/foundation/src/Kernel/AbstractKernel.php:239-247`). No production code instantiates `EntityValidator` (tests only).
- `EntityTypeValidationConstraints::forEntityType()` already merges entity-type-level manual constraints with builder-derived ones (manual *replaces* derived per field) and fail-louds on non-Constraint values.
- `FieldDefinitionConstraintBuilder` derives NotBlank/NotNull, Length, Email, Choice (allowed_values + enum), and scalar Type — no Range, and per-field `FieldDefinition::getConstraints()` (`packages/field/src/FieldDefinition.php:199`, returns `Constraint[]`) is never read. Array-shaped definitions DO carry `constraints` through `normalizeDefinition()` (`:130`), so the data is already there.
- `EntityUpdateTool::execute()` sets every string-keyed values entry verbatim; `EntityCreateTool::execute()` passes values raw to the entity constructor and calls `enforceIsNew()` when an id is supplied. Both catch `\Throwable` and flatten to `AgentToolResult::error(string)`.
- `SqlStorageDriver` excludes the id key from update field sets (`packages/entity-storage/src/Driver/SqlStorageDriver.php:212-218`) — currently an undocumented accident.

## D1 — Where the default validator is constructed

**Decision**: Add `EntityValidator::createDefault(): self` to `packages/entity/src/Validation/EntityValidator.php`, wrapping `Symfony\Component\Validator\Validation::createValidator()`. The kernel calls this once and shares the instance across all repositories it builds.

**Rationale**: Keeps the `symfony/validator` construction inside the package that already depends on it (`waaseyaa/entity`); the kernel (foundation, L0) already imports entity types under its sanctioned cross-layer exemption, so passing the instance is clean. One shared instance is safe — `EntityValidator` is stateless.

**Alternatives considered**: (a) Construct `Validation::createValidator()` directly in `AbstractKernel` — works, but adds a direct symfony/validator import to foundation and duplicates construction knowledge. (b) Container binding resolved per repository — heavier, and repositories are built inside a factory closure where a captured instance is idiomatic (matches how `$fieldRegistry` is passed today).

## D2 — Opt-out mechanism (FR-008)

**Decision**: Environment variable `WAASEYAA_ENTITY_VALIDATION`; values `0`, `false`, `off` (case-insensitive) disable kernel-level wiring. Default (unset or any other value) = enforcement ON. Documented in CHANGELOG upgrade note + `docs/specs/entity-system.md` + root `CLAUDE.md` Environment section. The pre-existing per-save `save($entity, validate: false)` remains the surgical escape hatch for framework-internal writes (migrations, bootstrap).

**Rationale**: The kernel reads env directly for its other boot-time switches (`APP_ENV`, `APP_DEBUG`, `WAASEYAA_DB`); validation wiring is a boot-time decision made before the config system is necessarily readable. Two-level opt-out (global env + per-save flag) covers both "consumer needs time to fix data" and "this specific write is exempt by design".

**Alternatives considered**: (a) Config key in `config/waaseyaa.php` — config loading order vs. repository factory construction makes this fragile; (b) No opt-out (pure break) — rejected: the spec's own edge cases (migrations, bootstrap) need a sanctioned bypass, and the alpha.200 precedent paired its break with an escape path.

## D3 — Create tool and pre-set IDs (the spec-fidelity wrinkle)

**Context**: `EntityCreateTool` today explicitly supports model-supplied `id` values (it calls `enforceIsNew()` to force the INSERT path — the CLAUDE.md gotcha). Unconditional identity-key refusal (spec scenario 5) removes that ability.

**Decision**: Refuse identity keys on create too, including `id`. Agent-created entities get system-assigned identity.

**Rationale**: The issue's principle is "identity is not content"; a model choosing primary keys is exactly the corruption/collision surface this mission closes (a hallucinated id that happens to exist becomes... an INSERT failure today, but a uuid/langcode collision is silent). The `enforceIsNew()` path remains for non-tool callers (services constructing entities directly) — only the *stock agent tools* refuse.

**Alternatives considered**: (a) Allow `id` on create, refuse the rest — inconsistent contract, keeps a model-controlled identity channel open; (b) per-type allowlist — that is #1638's scoped-write model, out of scope here. If a consumer genuinely needs agent-driven pre-set IDs they should write a custom tool; noted in the CHANGELOG.

## D4 — Refused-key resolution

**Decision**: `EntityKeyGuard` computes the refusal set as: registered entity-key column names for kinds `id`, `uuid`, `revision`, `langcode`, `default_langcode` (from `EntityTypeInterface::getKeys()`), unioned with the literal names `uuid`, `langcode`, `default_langcode`. The `label` and `bundle` keys are explicitly NOT refused (label is ordinary content like `title`; bundle is needed by create flows).

**Rationale**: Registered keys catch renamed columns (e.g. `nid`); the literal floor catches translatable schema columns that exist physically even when an entity type forgot to register a langcode key. Verified shape: `getKeys()` returns kind→column map (`['id' => 'nid', 'label' => 'title', ...]`).

**Alternatives considered**: Refusing all registered keys including label/bundle — would break ordinary content writes (title!) and create flows; rejected on first contact with reality.

## D5 — Per-field constraint merge semantics (FR-003)

**Decision**: In `constraintsForField()`, append `$def->getConstraints()` entries after the derived constraints. Entries that are not `Symfony\Component\Validator\Constraint` instances throw `InvalidArgumentException`.

**Rationale**: Append (rather than replace) lets declared constraints tighten derived ones; the existing type-level layer (`EntityTypeValidationConstraints`) already provides replace semantics when an app wants a full override — two layers, two behaviors, no new vocabulary (C-004 of the spec). Fail-loud on garbage matches `normalizeToList()` precedent.

**Alternatives considered**: Per-field replace semantics — would silently drop derived NotNull/Type when an app declares one extra rule; surprising and weaker.

## D6 — Structured tool error shape (FR-004/FR-007)

**Decision**: Both tools catch `EntityValidationException` before the generic `\Throwable` arm and return `AgentToolResult::error()` whose message is deterministic (`entity.update: validation failed: score: This value should be between 0 and 100.; title: ...` — violations sorted by field name) — and, where `AgentToolResult` supports content payloads on errors, attach `[['type' => 'json', 'data' => ['violations' => [...]]]]`. Identity-key refusals use message `entity.update: refused identity keys: langcode, uuid — identity fields cannot be written through this tool` with `refused_keys` in the payload. Exact shapes in [contracts/tool-refusal.md](contracts/tool-refusal.md).

**Rationale**: Deterministic ordering satisfies NFR-003 and makes the errors assertable; a JSON payload (if the result type allows content on error — to verify in WP, fall back to message-only otherwise) gives models a machine round-trip. No change to the `AgentToolResult` public API unless the error-content path already exists.

**Alternatives considered**: New exception-to-MCP error mapping layer — over-engineering for two tools; revisit if #1638's scoping lands a general policy layer.

## Performance note (NFR-001)

`EntityTypeValidationConstraints::forEntityType()` recomputes the constraint map per save. For ≤20 fields this is array assembly + a handful of constraint object constructions — expected well under the 10% envelope (the save path already does field resolution, events, and SQL). The integration perf smoke (compare 200 saves validated vs `validate: false`) pins it. If it ever breaches, memoize the constraint map per `(entity_type, bundle)` inside the repository — explicitly deferred until measured.
