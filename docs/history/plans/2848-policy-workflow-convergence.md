# #2848 — manual policy/workflow scaffold convergence (bounded slice)

Session design artifact (historical), not an enduring spec. Scope: the
CLI-facing "manual scaffold" half of #2848 — `make:policy`/`policy.stub` and
`scaffold:workflow` — converging on the same canonical shapes the blueprint
compiler (#2788) already emits through `AccessPolicyEmitter` and
`WorkflowDefinitionEmitter`. Root's shared admission/activation work (#2857,
the #2846 unified generation authority) is out of scope; nothing here writes
a file, activates a Composer provider, or imports config.

## Problem, precisely

- `MakePolicyHandler`/`policy.stub` render a class that claims
  `implements AccessPolicyInterface` but has `view()`/`create()`/`update()`/
  `delete()` methods — **the real interface**
  (`packages/access/src/AccessPolicyInterface.php`) requires
  `access(EntityInterface, string, AccountInterface)`,
  `createAccess(string, string, AccountInterface)`, `appliesTo(string)`.
  The generated class does not satisfy its declared interface: it is a
  fatal error if ever loaded, not merely "unbound."
- `WorkflowScaffoldHandler` emits JSON whose `states`/`transitions` shape
  cannot hydrate a real `Waaseyaa\Workflows\Workflow` (missing `label`,
  `published`, `default_revision`, `initial_state`; `transitions` keyed
  positionally instead of by id; `from` forced to a single state). It also
  has no `entity_type` input, so its `--bundle` alone cannot form a valid
  `workflows.assignments` key (`WorkflowBindingResolver` keys on
  `{entity_type}.{bundle}`).

## Decision: converge by sharing the canonical renderer, not by writing files

ADR-025 D-4 classifies both commands **`keep`**: stdout-only, zero side
effects, no follow-on issue for the unified generation-unit authority. This
slice does not change that disposition — it only fixes what the stdout
content *is*. Concretely:

1. `AccessPolicyEmitter` gains a new `public` method,
   `renderPolicyClass(string $entityId, ?string $ownerField, string $className, array $policies, bool $workflowBound): string`,
   a thin wrapper that builds a minimal `BlueprintEntity` and delegates to
   the existing, untouched, golden-byte-locked private `renderPolicy()`.
   `MakePolicyHandler` calls this directly — both paths render through the
   exact same code. `renderPolicy()`'s body, `emit()`, and every existing
   `AccessPolicyEmitterTest` assertion are unchanged (verified below).
2. `WorkflowDefinitionEmitter` gains a new `public` method,
   `toDefinitionArray(BlueprintWorkflow $workflow): array`, returning the
   plain `Workflow::__construct()` hydration array (not PHP source) using
   the same derivation rules the existing PHP-source renderer already
   documents (`default_revision := published`, states/transitions sorted by
   id). `WorkflowScaffoldHandler` builds a `BlueprintWorkflow` from its CLI
   input and calls this to produce the JSON `workflow` payload. The
   existing `renderDefinition()`/`renderStateRow()`/`renderTransitionRow()`
   and every existing `WorkflowDefinitionEmitterTest` assertion are
   unchanged.
3. Neither new method throws `GenerationRefusalException`, references any
   `ArtifactPlan`/`EvaluatedArtifactPlan`/`ArtifactApplyRequest`/
   `ArtifactApplyResult`/`ChangeReceipt` type, or calls any authority seam
   (`receiptFor`/`inspectUnits`/`readUnitMetadata`/`readComposerProviderState`/
   `prepareUnitPlan`/`SiteInitializationService::evaluate|apply`) — required
   so `MakePolicyHandler.php`/`WorkflowScaffoldHandler.php` (both
   `packages/cli/src/Handler/`, an entrypoint root) stay outside
   `tests/Architecture/GenerationStagedActivationBoundaryTest.php`'s closed
   allowlists. Malformed CLI input is refused locally with an ordinary
   `SymfonyCommandIO::error()` + exit `2`, the same convention
   `WorkflowScaffoldHandler` already uses for a malformed `--transition`.

## CLI contract changes (both deliberately breaking; old shape was invalid/unusable)

### `make:policy <name> --entity=<id> [--grant=<operation>:<permission>]...`

- `--entity` (new, required): the entity type id this policy governs —
  written into `#[PolicyAttribute(entityType: ...)]` and `appliesTo()`.
  Validated with `AbstractMakeHandler::validateMachineName()`.
- `--grant` (new, repeatable, optional): `<operation>:<permission>` where
  `operation` is one of `view|create|update|delete`
  (`BlueprintOperation`). Only the `permission` condition kind is supported
  at this bounded CLI grammar — `ownership`/`workflow_state` conditions need
  entity metadata (`keys.owner`, workflow binding) a bare `make:policy`
  invocation has no way to declare; that stays blueprint-only. Zero
  `--grant`s renders an entity-bound, all-`Neutral` (default-deny) class —
  still real and registrable, unlike today's non-interface-satisfying stub.
  Multiple grants for the same operation combine with OR, first-listed wins
  (mirrors `AccessPolicyEmitter`'s documented policy-id-order determinism;
  here the CLI argument order is the analogous deterministic order).
- Unknown/malformed `--grant`, missing `--entity` -> exit `2` (usage error),
  matching `docs/adr/025-unified-artifact-generation-authority.md` D-5's
  general CLI exit convention already in force. The three existing
  identifier-injection rejection tests (quote breakout, path traversal,
  newline injection on `name`) are unaffected.
- `policy.stub` stops being the runtime template (a single static file
  cannot represent a variable-length per-operation match arm/method list
  without reimplementing `AccessPolicyEmitter`'s heredoc builder a second
  time, which would risk exactly the byte drift this slice exists to
  prevent). Its content is rewritten to the exact zero-grant canonical
  shape (documentation of what an unbound-but-valid policy looks like) with
  a header comment pointing at the real runtime path.

### `scaffold:workflow --id=<id> --entity-type=<id> --bundle=<id> [--initial-state=<id>] [--state=<id>]... [--transition=<id>:<from>[,<from>...]:<to>:<permission>]...`

- `--entity-type` (new, required): paired with the existing `--bundle` to
  form the real `workflows.assignments` key `{entity_type}.{bundle}`
  (`WorkflowBindingResolver`'s exact raw config shape) — the old handler
  had no way to express this at all.
- `--initial-state` (new, optional): defaults to the first `--state` given
  (CLI order), or `draft` when defaults are used.
- `--state=<id>` grammar is unchanged syntactically; `label` is now derived
  (`titleCase(id)`) and `published` defaults to `id === 'published'` — both
  were previously absent, which is exactly why the old JSON could not
  hydrate a real `Workflow`.
- `--transition=<id>:<from>:<to>:<permission>` grammar is unchanged
  syntactically except `<from>` may now be a comma-separated list
  (`draft,review`); a single value with no comma still parses as a
  one-element list. `label` is derived the same way as states.
- Output becomes `{"workflow": <Workflow::__construct() hydration array>,
  "assignment": {"<entity_type>.<bundle>": "<id>"}}` — literally
  `new Workflow($decoded['workflow'])` and
  `$assignments["$entityType.$bundle"] = $decoded['assignment'][...]` are
  now real, valid inputs, proven in the focused tests below via
  `WorkflowValidator` and the real `WorkflowBindingResolver`.

## Proof obligations (focused tests, this slice)

- `MakePolicyCommandTest`: generated class round-trips through
  `EntityAccessHandler` — an authorized permission holder is `allowed()`,
  an anonymous/permissionless account is not; `#[PolicyAttribute]` carries
  the declared entity id; zero-grant output stays all-`Neutral`
  (default-deny); the three existing injection-rejection tests stay green;
  a new "missing `--entity`" exit-2 test.
- `AccessPolicyEmitterTest`: existing assertions unchanged (golden bytes);
  one new test that `renderPolicyClass()` produces output loadable and
  behaviorally identical in shape to what `renderPolicy()` already proves
  (convergence, not duplication, of the runtime-evaluation proof).
- `WorkflowDefinitionEmitterTest`: existing assertions unchanged (golden
  bytes); one new test that `toDefinitionArray()` on the `complete.yaml`
  fixture's `editorial` workflow matches the same states/transitions/
  `initial_state` the existing golden `EditorialWorkflowDefinition::
  DEFINITION` class evaluates to.
- `WorkflowScaffoldHandlerTest`: rewritten for the new canonical shape;
  `new Workflow($decoded['workflow'])` passes `WorkflowValidator::validate()
  === []`; the emitted `assignment` resolves through a real
  `WorkflowBindingResolver` (in-memory `ConfigFactoryInterface` +
  `EntityTypeManagerInterface`, the same fixture shape
  `WorkflowBindingResolverTest` already uses) to the exact workflow just
  defined, and `Workflow::permissionFor()` returns the declared permission
  verbatim.

## Explicitly out of scope (recorded, not silently dropped)

- Activation/config-import authority, Composer provider wiring, and the
  #2846 unified generation-unit/transaction engine — root's territory
  (#2857). This slice's outputs remain exactly what ADR-025 already
  classifies these two commands as: text an operator reviews and pastes in
  by hand.
- `ownership`/`workflow_state` policy conditions in `make:policy` (no entity
  metadata available at this CLI grammar layer).
- Any change to `ApplicationBlueprintCompiler`, its factory,
  `SiteInitializationService`, recipe/renderer/provider-registration files,
  shared `complete.yaml`/expected-fixture bytes, CI, `public-surface.php`
  aggregates, or `tests/Architecture/GenerationStagedActivationBoundaryTest.php`
  — all preserved verbatim; verified by focused diff review before
  reporting completion.
