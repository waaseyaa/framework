# CW-v1 WP-0 + WP-1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WP-0 closes the self-publish hole with an interim field gate; WP-1 builds the content-workflow engine core (config schema extensions, TransitionService, save-path guard, events, default editorial workflow) per `docs/specs/content-workflow.md`.

**Architecture:** Extend the existing `Workflow` config entity (`ConfigEntityBase`, already registered as entity type `workflow`) with state flags, transition permissions, and an initial state; add a `TransitionService` as the single enforcement door plus a `PRE_SAVE` guard listener; introduce a minimal request-scoped `AccountContext` in the access package so the guard knows the acting account.

**Tech Stack:** PHP 8.5, Symfony EventDispatcher (contracts FQCN via kernel-services bus), PHPUnit 10.5, existing waaseyaa entity/access/config/audit packages.

## Global Constraints

- `declare(strict_types=1)` in every file; `final class` for concrete implementations; `final readonly class` for value objects.
- No psr/log: `Waaseyaa\Foundation\Log\LoggerInterface`, accepted as `?LoggerInterface $logger = null`, defaulted to `NullLogger`.
- PHPUnit 10.5 attributes (`#[Test]`, `#[CoversClass]`); NEVER pass `-v` to phpunit.
- Entity queries need `->setAccount()` or `->accessCheck(false)` + justification comment (`bin/check-getquery-bindings` gates new offenders).
- Layer rule: `workflows` (L3) imports only L0–L2 + same-layer; `access`/`user` are L1. No upward imports.
- Anchoring issue: **#1920**. PR titles: `feat(#1920): …` / `fix(#1920): …`. PR bodies reference #1920, never `Closes #1920`.
- Commits end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- The pre-push hook runs phpstan + the full 9,4xx-test suite; keep it green at every push.
- Permission name constant (spec-pinned, do not vary): `use editorial transition publish`.
- New behavior documented in `docs/specs/content-workflow.md` — flip the relevant WP row to `landed` in the same PR that ships it.

---

# WP-0: Interim status/workflow_state field gate

Branch: `fix/cw-wp0-status-gate` from `origin/main`. One PR at the end.

### Task 0.1: NodeAccessPolicy publish gate

**Files:**
- Modify: `packages/node/src/NodeAccessPolicy.php`
- Test: `packages/node/tests/Unit/NodeAccessPolicyTest.php` (extend existing)

**Interfaces:**
- Consumes: `AccessResult::forbidden(string)/neutral(string)`, `AccountInterface::hasPermission(string): bool` — existing.
- Produces: `NodeAccessPolicy::PUBLISH_PERMISSION = 'use editorial transition publish'` public const (Task 0.2 and WP-1 reference it).

- [ ] **Step 1: Write the failing tests**

Add to `NodeAccessPolicyTest` (follow the file's existing account-double pattern — accounts are built with explicit permission lists):

```php
#[Test]
public function status_edit_is_forbidden_without_the_publish_permission(): void
{
    $policy = new NodeAccessPolicy();
    $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7, 'status' => 0]);
    $account = $this->accountWithPermissions(7, ['edit own article content']);

    $result = $policy->fieldAccess($node, 'status', 'edit', $account);

    $this->assertTrue($result->isForbidden());
}

#[Test]
public function workflow_state_edit_is_forbidden_without_the_publish_permission(): void
{
    $policy = new NodeAccessPolicy();
    $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7]);
    $account = $this->accountWithPermissions(7, ['edit own article content']);

    $this->assertTrue($policy->fieldAccess($node, 'workflow_state', 'edit', $account)->isForbidden());
}

#[Test]
public function status_edit_is_open_with_the_publish_permission(): void
{
    $policy = new NodeAccessPolicy();
    $node = new Node(['nid' => 1, 'type' => 'article', 'uid' => 7, 'status' => 0]);
    $account = $this->accountWithPermissions(7, ['edit own article content', NodeAccessPolicy::PUBLISH_PERMISSION]);

    $this->assertFalse($policy->fieldAccess($node, 'status', 'edit', $account)->isForbidden());
}

#[Test]
public function status_gate_applies_on_create_too(): void
{
    $policy = new NodeAccessPolicy();
    $node = new Node(['type' => 'article', 'uid' => 7, 'status' => 1]);
    $node->enforceIsNew();
    $account = $this->accountWithPermissions(7, ['create article content']);

    $this->assertTrue($policy->fieldAccess($node, 'status', 'edit', $account)->isForbidden());
}
```

If the test file has no `accountWithPermissions()` helper, add one returning an anonymous class implementing `AccountInterface` with the given id + permission list (PHPUnit `createMock()` cannot mock intersection types used elsewhere in this suite — follow the file's existing approach).

- [ ] **Step 2: Run to verify the new tests fail**

Run: `./vendor/bin/phpunit packages/node/tests/ --filter NodeAccessPolicyTest`
Expected: the 4 new tests FAIL (gate not implemented); existing tests PASS.

- [ ] **Step 3: Implement the gate**

In `NodeAccessPolicy`, add below `ADMIN_ONLY_EDIT_FIELDS`:

```php
/**
 * Interim CW-v1 publish gate (WP-0, spec: docs/specs/content-workflow.md).
 * Named after the engine's editorial publish transition so nothing renames
 * when TransitionService lands.
 */
public const string PUBLISH_PERMISSION = 'use editorial transition publish';

/** @var list<string> */
private const PUBLISH_GATED_FIELDS = ['status', 'workflow_state'];
```

In `fieldAccess()`, after the `administer nodes` early-return, before the `!$entity->isNew()` block:

```php
// CW-v1 WP-0: publication is permission-gated on create AND update. An
// account with only edit/create permissions must not self-publish to
// anonymous visibility (audit D1). Unlike ADMIN_ONLY_EDIT_FIELDS below,
// this gate has no isNew() carve-out.
if (\in_array($fieldName, self::PUBLISH_GATED_FIELDS, true)
    && !$account->hasPermission(self::PUBLISH_PERMISSION)) {
    return AccessResult::forbidden(\sprintf(
        "Field '%s' requires the '%s' permission.",
        $fieldName,
        self::PUBLISH_PERMISSION,
    ));
}
```

Update the class docblock: replace the sentence "The editorial booleans `status`/`promote`/`sticky` are intentionally NOT gated here (a separate permission-model decision)." with "The publication fields `status`/`workflow_state` are permission-gated (CW-v1 WP-0, `docs/specs/content-workflow.md`); `promote`/`sticky` remain ungated pending the engine."

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/node/tests/`
Expected: PASS. If pre-existing tests assumed ungated status edits, update them to grant `NodeAccessPolicy::PUBLISH_PERMISSION` — list every such change in the PR body.

- [ ] **Step 5: Commit**

```bash
git add packages/node/src/NodeAccessPolicy.php packages/node/tests/Unit/NodeAccessPolicyTest.php
git commit -m "fix(#1920): permission-gate node status/workflow_state edits (CW-v1 WP-0)"
```

### Task 0.2: Unpublished default on gated create

**Files:**
- Modify: `packages/api/src/JsonApiController.php` (create path, near line 481–530)
- Test: `packages/api/tests/` — extend the controller create tests (find via `rg -l "checkCreateAccess" packages/api/tests/`)

**Interfaces:**
- Consumes: `EntityAccessHandler::checkFieldAccess($entity, string $field, string $op, AccountInterface): AccessResult` — existing; `NodeAccessPolicy::PUBLISH_PERMISSION` (Task 0.1).
- Produces: generic controller behavior — no new public surface.

- [ ] **Step 1: Write the failing test**

In the api controller create test file, add: creating a node (no explicit `status` in the payload) as an account holding only `create article content` + `access content` must yield an UNPUBLISHED entity; the same create by an account also holding `use editorial transition publish` stays published-by-default. Follow the test file's existing fixture pattern for registering the node type + policy; assert via the stored entity's `status` value.

```php
#[Test]
public function create_without_status_defaults_unpublished_for_accounts_that_may_not_publish(): void
{
    // Arrange per this file's existing create-test fixture (entity type
    // 'node', NodeAccessPolicy registered, account WITHOUT the publish perm).
    $document = $this->controller->create('node', [
        'type' => 'article',
        'title' => 'Draft by author',
    ]);

    $stored = $this->storage->load($this->idFrom($document));
    $this->assertSame(0, (int) $stored->get('status'));
}

#[Test]
public function create_without_status_stays_published_for_publishers(): void
{
    // Same fixture, account WITH NodeAccessPolicy::PUBLISH_PERMISSION.
    $document = $this->controller->create('node', [
        'type' => 'article',
        'title' => 'Published by editor',
    ]);

    $stored = $this->storage->load($this->idFrom($document));
    $this->assertSame(1, (int) $stored->get('status'));
}
```

Adapt names/fixture calls to the real test file — the two behaviors above are the requirement.

- [ ] **Step 2: Run to verify the first test fails**

Run: `./vendor/bin/phpunit packages/api/tests/ --filter create_without_status`
Expected: first test FAILS (status is 1 — Node constructor default); second PASSES.

- [ ] **Step 3: Implement**

In `JsonApiController::create()`, after the entity is constructed from `$data` and after the per-supplied-field access checks, add:

```php
// CW-v1 WP-0 (docs/specs/content-workflow.md): an entity constructor may
// default `status` to published (Node does), but an account forbidden from
// editing `status` must not create born-published content. Applies only
// when the client did not supply `status` (a supplied value was already
// access-checked above).
if (!\array_key_exists('status', $data)
    && $entity->get('status') !== null
    && $this->accessHandler->checkFieldAccess($entity, 'status', 'edit', $this->account)->isForbidden()) {
    $entity->set('status', 0);
}
```

Match the surrounding code's actual variable names (`$entity`, `$data`, `$this->accessHandler`, `$this->account` — verify against the file).

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/api/tests/ && ./vendor/bin/phpunit packages/node/tests/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/api/src/JsonApiController.php packages/api/tests/
git commit -m "fix(#1920): gated accounts create unpublished by default (CW-v1 WP-0)"
```

### Task 0.3: Gates, spec row, PR

- [ ] **Step 1: Full verification**

Run: `set -o pipefail; composer phpstan && ./vendor/bin/phpunit --testsuite Unit && ./vendor/bin/phpunit --testsuite Integration`
Expected: green. Also run `php bin/check-getquery-bindings` (no new offenders expected — no queries added).

- [ ] **Step 2: Flip the spec WP row**

In `docs/specs/content-workflow.md`, WP table: WP-0 status `pending (ships on R16 timeline)` → `landed (PR pending review)`.

- [ ] **Step 3: Push + PR**

```bash
git add docs/specs/content-workflow.md
git commit -m "docs(#1920): mark WP-0 landed in content-workflow spec"
git push -u origin fix/cw-wp0-status-gate
gh pr create --title "fix(#1920): CW-v1 WP-0 — permission-gate publication (status/workflow_state)" \
  --body "$(printf '**Anchoring issue / plan:** #1920 (CW-v1), docs/history/plans/2026-07-06-content-workflow-wp0-wp1.md\n\n## Summary\n- NodeAccessPolicy: status/workflow_state edits require `use editorial transition publish` (create AND update); docblock posture updated\n- JsonApiController::create: constructor-defaulted published status is floored to unpublished for accounts forbidden from editing status\n- Deny-path tests for both\n\nBehavior change: accounts with only edit/create permissions can no longer self-publish. Existing-test adjustments are listed below.\n')"
```

---

# WP-1: Engine core

Branch: `feat/cw-wp1-engine` from `origin/main` (rebase onto main after WP-0 merges if it lands first — WP-1 Task 1.7 reuses `NodeAccessPolicy::PUBLISH_PERMISSION` only in tests; no hard dependency). One PR at the end; commit per task.

New file layout in `packages/workflows/src/`:

```
src/
  Workflow.php                    (modify: initial_state, flags hydration, permissionFor())
  WorkflowState.php               (modify: published/defaultRevision flags)
  WorkflowTransition.php          (modify: permission)
  Validation/WorkflowValidator.php
  Binding/WorkflowBindingResolver.php
  Transition/TransitionService.php
  Transition/TransitionResult.php
  Transition/TransitionDeniedException.php
  Event/WorkflowEvents.php
  Event/WorkflowTransitionEvent.php
  Listener/WorkflowStateGuard.php
  DefaultWorkflows.php            (declarative seed data)
  WorkflowServiceProvider.php     (modify: bindings + boot() wiring + seed)
```

No new access-package files: `Waaseyaa\Access\Context\AccountContextInterface` (`current(): ?AccountInterface`, `set(?AccountInterface): void`) and `RequestAccountContext` ALREADY EXIST — SessionMiddleware already overwrites the context unconditionally on every request, MCP endpoint and agent executor set-and-restore, and the revision save pipeline reads it for revision authorship. Consume, don't create.

### Task 1.1: WorkflowState flags

**Files:**
- Modify: `packages/workflows/src/WorkflowState.php`, `packages/workflows/src/Workflow.php` (hydration)
- Test: `packages/workflows/tests/Unit/WorkflowStateTest.php`, `WorkflowTest.php`

**Interfaces:**
- Produces: `WorkflowState::__construct(string $id, string $label, int $weight = 0, array $metadata = [], bool $published = false, bool $defaultRevision = false)` — later tasks read `$state->published` / `$state->defaultRevision`.

- [ ] **Step 1: Failing tests** — `WorkflowState` exposes `published`/`defaultRevision` (default false); `Workflow` hydrates them from array config:

```php
#[Test]
public function states_hydrate_published_and_default_revision_flags(): void
{
    $workflow = new Workflow(['id' => 'editorial', 'states' => [
        'draft' => ['label' => 'Draft'],
        'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
    ]]);

    $this->assertFalse($workflow->getState('draft')->published);
    $this->assertTrue($workflow->getState('published')->published);
    $this->assertTrue($workflow->getState('published')->defaultRevision);
}
```

(Verify the accessor name — `getState()`/`getStates()` — against the existing `Workflow` class and use what exists.)

- [ ] **Step 2: Run; expect FAIL** — `./vendor/bin/phpunit packages/workflows/tests/ --filter hydrate_published`
- [ ] **Step 3: Implement** — add the two readonly constructor params (defaults false); in `Workflow::__construct` array-hydration branch pass `published: (bool) ($stateData['published'] ?? false), defaultRevision: (bool) ($stateData['default_revision'] ?? false)`.
- [ ] **Step 4: Run; expect PASS** — whole workflows suite: `./vendor/bin/phpunit packages/workflows/tests/`
- [ ] **Step 5: Commit** — `feat(#1920): workflow states carry published/default_revision flags (WP-1)`

### Task 1.2: Transition permissions + initial state

**Files:**
- Modify: `packages/workflows/src/WorkflowTransition.php`, `packages/workflows/src/Workflow.php`
- Test: `packages/workflows/tests/Unit/WorkflowTest.php`

**Interfaces:**
- Produces: `WorkflowTransition::$permission` (string, may be `''`); `Workflow::getInitialState(): string`; `Workflow::permissionFor(WorkflowTransition $t): string` (returns `$t->permission` or derives `use {workflowId} transition {transitionId}`); `Workflow::getTransition(string $id): ?WorkflowTransition` (add if absent).

- [ ] **Step 1: Failing tests:**

```php
#[Test]
public function transition_permission_defaults_to_the_derived_name(): void
{
    $workflow = new Workflow(['id' => 'editorial',
        'states' => ['draft' => ['label' => 'Draft'], 'review' => ['label' => 'Review']],
        'transitions' => ['submit' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review']],
    ]);

    $this->assertSame('use editorial transition submit',
        $workflow->permissionFor($workflow->getTransition('submit')));
}

#[Test]
public function explicit_transition_permission_wins(): void
{
    $workflow = new Workflow(['id' => 'editorial',
        'states' => ['draft' => ['label' => 'Draft'], 'review' => ['label' => 'Review']],
        'transitions' => ['submit' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review', 'permission' => 'custom perm']],
    ]);

    $this->assertSame('custom perm', $workflow->permissionFor($workflow->getTransition('submit')));
}

#[Test]
public function initial_state_hydrates_and_defaults_to_the_first_state(): void
{
    $explicit = new Workflow(['id' => 'w', 'initial_state' => 'draft',
        'states' => ['x' => ['label' => 'X'], 'draft' => ['label' => 'Draft']]]);
    $implicit = new Workflow(['id' => 'w', 'states' => ['x' => ['label' => 'X']]]);

    $this->assertSame('draft', $explicit->getInitialState());
    $this->assertSame('x', $implicit->getInitialState());
}
```

- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** — `permission: (string) ($transitionData['permission'] ?? '')` in hydration; `permissionFor()` = `$t->permission !== '' ? $t->permission : sprintf('use %s transition %s', (string) $this->id(), $t->id)`; store `initial_state` value with first-state fallback.
- [ ] **Step 4: Run workflows suite; expect PASS.**
- [ ] **Step 5: Commit** — `feat(#1920): transition permissions + workflow initial state (WP-1)`

### Task 1.3: WorkflowValidator

**Files:**
- Create: `packages/workflows/src/Validation/WorkflowValidator.php`
- Test: `packages/workflows/tests/Unit/Validation/WorkflowValidatorTest.php`

**Interfaces:**
- Produces: `WorkflowValidator::validate(Workflow $workflow): list<string>` — empty list = valid; each string a human-readable violation. Used by Task 1.8 (seed) and the config-import surface later.

- [ ] **Step 1: Failing tests** — violations for: transition `from` state unknown; transition `to` state unknown; `initial_state` not a defined state; zero states; more than one state flagged `published && default_revision`… no — multiple published states ARE legal (published, archived is not published; two published default-revision states are legal in Drupal too). Validate exactly: unknown from/to, unknown initial_state, zero states, duplicate transition targeting (same id handled by keying). Write one test per violation + one valid-workflow test asserting `[]`.

```php
#[Test]
public function a_transition_from_an_unknown_state_is_a_violation(): void
{
    $workflow = new Workflow(['id' => 'w',
        'states' => ['draft' => ['label' => 'Draft']],
        'transitions' => ['bad' => ['label' => 'Bad', 'from' => ['nope'], 'to' => 'draft']]]);

    $violations = new WorkflowValidator()->validate($workflow);

    $this->assertCount(1, $violations);
    $this->assertStringContainsString("unknown state 'nope'", $violations[0]);
}
```

- [ ] **Step 2: Run; expect FAIL** (class missing).
- [ ] **Step 3: Implement** — final class, no dependencies, pure checks over `$workflow->getStates()/getTransitions()/getInitialState()`.
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(#1920): workflow definition validator (WP-1)`

### Task 1.4: WorkflowBindingResolver

**Files:**
- Create: `packages/workflows/src/Binding/WorkflowBindingResolver.php`
- Test: `packages/workflows/tests/Unit/Binding/WorkflowBindingResolverTest.php`

**Interfaces:**
- Consumes: `ConfigFactoryInterface::get('workflows.assignments'): ConfigInterface` (raw data shape: `['node.article' => 'editorial', 'node.*' => 'editorial']`); `EntityTypeManager::getEntityType()/isRevisionable()`; workflow storage `EntityTypeManager::getStorage('workflow')->load($id)`.
- Produces: `WorkflowBindingResolver::resolve(string $entityTypeId, string $bundle): ?Workflow` (null = unbound); throws `\RuntimeException` when a binding names a non-revisionable type or an unknown workflow. Exact-bundle key wins over `{type}.*` wildcard.

- [ ] **Step 1: Failing tests** — bound exact, bound wildcard, unbound returns null, non-revisionable bound type throws, unknown workflow id throws. Build with in-memory doubles: a stub ConfigFactory returning fixed raw data; an EntityTypeManager fixture with a revisionable + non-revisionable test type (follow existing workflows tests for EntityTypeManager fixtures, or use anonymous stubs of the interfaces actually consumed).
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** (final class; constructor `(ConfigFactoryInterface $configFactory, EntityTypeManager $entityTypeManager)`; memoize resolved workflows per instance — boot-stable like the wayfinding registry; comment why worker-mode safe).
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(#1920): workflow bindings resolver (WP-1)`

### Task 1.5: AccountContext — verify-and-consume (NO new code)

`Waaseyaa\Access\Context\AccountContextInterface` already exists with exactly the contract later tasks need (`current(): ?AccountInterface`, `set(?AccountInterface): void`; three-state semantics documented on the interface: account N / anonymous 0 / null = no acting context). SessionMiddleware already overwrites it unconditionally per request; MCP endpoint and agent executor set-and-restore in `finally`; `SaveContext`/`EntityRepository` already read it for revision authorship.

- [ ] **Step 1:** Read `packages/access/src/Context/AccountContextInterface.php` and one existing consumer (`packages/search/src/Access/EntitySearchAccessChecker.php` or `packages/mcp/src/McpEndpoint.php`) to copy the injection pattern (how the binding is resolved in a service provider).
- [ ] **Step 2:** No implementation, no commit. Tasks 1.7/1.8 inject `AccountContextInterface` following that pattern.

### Task 1.6: Events + denial exception + result

**Files:**
- Create: `packages/workflows/src/Event/WorkflowEvents.php`, `Event/WorkflowTransitionEvent.php`, `Transition/TransitionDeniedException.php`, `Transition/TransitionResult.php`
- Test: `packages/workflows/tests/Unit/Event/WorkflowTransitionEventTest.php`

**Interfaces:**
- Produces:

```php
enum WorkflowEvents: string
{
    case PRE_TRANSITION = 'waaseyaa.workflow.pre_transition';
    case POST_TRANSITION = 'waaseyaa.workflow.post_transition';
}

// extends Symfony\Contracts\EventDispatcher\Event, mirroring EntityEvent
final class WorkflowTransitionEvent extends Event
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly string $workflowId,
        public readonly string $transitionId,
        public readonly string $fromState,
        public readonly string $toState,
        public readonly ?AccountInterface $account,
    ) {}
}

final class TransitionDeniedException extends \RuntimeException
{
    // machine-readable reason codes; exact set:
    public const string REASON_UNBOUND = 'unbound';
    public const string REASON_UNKNOWN_TRANSITION = 'unknown_transition';
    public const string REASON_ILLEGAL_EDGE = 'illegal_edge';
    public const string REASON_PERMISSION = 'permission';
    public function __construct(public readonly string $reason, string $message) { parent::__construct($message); }
}

final readonly class TransitionResult
{
    public function __construct(
        public string $fromState,
        public string $toState,
        public string $transitionId,
    ) {}
}
```

- [ ] Steps: tests (event carries values; exception exposes reason) → red → implement → green → commit `feat(#1920): workflow transition events + typed denial (WP-1)`.

### Task 1.7: TransitionService

**Files:**
- Create: `packages/workflows/src/Transition/TransitionService.php`
- Test: `packages/workflows/tests/Unit/Transition/TransitionServiceTest.php`

**Interfaces:**
- Consumes: `WorkflowBindingResolver::resolve()` (1.4), `Workflow::permissionFor()/getTransition()/getState()` (1.1–1.2), `WorkflowEvents`/`WorkflowTransitionEvent`/`TransitionDeniedException`/`TransitionResult` (1.6), Symfony contracts `EventDispatcherInterface`, `AuditWriterInterface::record(AuditEventDescriptor)` (nullable), entity persistence via `EntityTypeManager::getStorage($entity->getEntityTypeId())->save($entity)`.
- Produces:

```php
final class TransitionService
{
    public function __construct(
        private readonly WorkflowBindingResolver $bindings,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?AuditWriterInterface $auditWriter = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @throws TransitionDeniedException */
    public function transition(EntityInterface $entity, string $transitionId, AccountInterface $account): TransitionResult;

    /** @return list<WorkflowTransition> transitions the account may fire from the entity's current state */
    public function getAvailableTransitions(EntityInterface $entity, AccountInterface $account): array;
}
```

Behavior (validate → apply → announce, in this order):
1. Resolve workflow; none → deny `REASON_UNBOUND`.
2. `getTransition($transitionId)`; missing → `REASON_UNKNOWN_TRANSITION`.
3. Current state = `(string) ($entity->get('workflow_state') ?? $workflow->getInitialState())`; not in `$transition->from` → `REASON_ILLEGAL_EDGE`.
4. `$account->hasPermission($workflow->permissionFor($transition))` false → `REASON_PERMISSION`. (Group constraints: WP-3 — do NOT stub them here.)
5. Dispatch PRE event; set `workflow_state` = `$transition->to`; set `status` = target state `published` flag (1/0); persist via storage save. Revision promotion (`default_revision` mechanics) is WP-2 — v1 apply sets fields on the entity as-is.
6. Dispatch POST event; audit best-effort (try/catch + logger — CLAUDE.md best-effort rule):

```php
$this->auditWriter?->record(new AuditEventDescriptor(
    kind: AuditEventKind::WorkflowTransition,
    accountUid: $account->isAuthenticated() ? (int) $account->id() : 0,
    subjectUri: sprintf('entity:%s/%s', $entity->getEntityTypeId(), (string) $entity->id()),
    outcome: 'allowed',
    severity: 'notice',
    entityTypeId: $entity->getEntityTypeId(),
    attributes: ['workflow' => $workflowId, 'transition' => $transitionId, 'from' => $from, 'to' => $to],
));
```

Requires adding `case WorkflowTransition = 'workflow.transition';` to `packages/audit/src/Enum/AuditEventKind.php` (one-line, plus its test if the enum has one). Denied transitions also audit with `outcome: 'denied'` before throwing.

- [ ] **Step 1: Failing tests** — happy transition (state+status set, storage save called, PRE+POST dispatched in order, audit recorded); each deny reason (4 tests, assert reason + no save + no POST event + denied audit); `getAvailableTransitions` filters by from-state and permission. Use a revisionable in-memory fixture entity type and stub bindings/storage per the package's existing test doubles.
- [ ] **Step 2: Run; expect FAIL.** — `./vendor/bin/phpunit packages/workflows/tests/ --filter TransitionService`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run workflows + audit suites; expect PASS.**
- [ ] **Step 5: Commit** — `feat(#1920): TransitionService — the one enforcement door (WP-1)`

### Task 1.8: Save-path guard + provider wiring + seed + wiring regression test

**Files:**
- Create: `packages/workflows/src/Listener/WorkflowStateGuard.php`, `packages/workflows/src/DefaultWorkflows.php`
- Modify: `packages/workflows/src/WorkflowServiceProvider.php` (bind resolver/service/guard; add `boot()`; seed default workflow)
- Test: `packages/workflows/tests/Unit/Listener/WorkflowStateGuardTest.php`, `packages/workflows/tests/Integration/GuardWiringTest.php`

**Interfaces:**
- Consumes: `EntityEvents::PRE_SAVE` (value `'waaseyaa.entity.pre_save'`), `EntityEvent { entity, originalEntity }`, existing `AccountContextInterface` (1.5), `WorkflowBindingResolver` (1.4), `TransitionService` validation pieces (1.7).
- Produces: `WorkflowStateGuard::onPreSave(EntityEvent $event): void`.

NOTE: entity-storage also dispatches its own `BeforeSaveEvent`/`AfterSaveEvent` (`Waaseyaa\EntityStorage\Event\`). Verify which event the real content save path fires (check what `EntityLifecycleAuditListener` subscribes to — it is a known-live listener) and hook the guard to that one; the wiring regression test in this task is what catches a wrong choice.

Guard rules (only for entities whose type+bundle resolve to a workflow; everything else returns immediately):
1. **New entity:** force `workflow_state` to `getInitialState()` when unset; if set to anything else, allow only if it is `initial_state` OR the context account may reach it via a single legal+permitted transition from initial state; otherwise throw `TransitionDeniedException`. Force `status` to the state's `published` flag.
2. **Existing entity, `workflow_state` unchanged:** force `status` consistent with the current state's `published` flag (state owns status on bound types).
3. **Existing entity, `workflow_state` changed:** find a transition with `from` containing the original state and `to` equal to the new state; none → `REASON_ILLEGAL_EDGE`. If `AccountContextInterface::current()` returns an account → require its permission (`REASON_PERMISSION` otherwise). Null context (CLI/queue/programmatic) → edge-legality only; docblock states programmatic callers should use `TransitionService`.

Provider `boot()` — mirror `RelationshipServiceProvider::boot()` exactly (the dispatcher MUST be resolved by the Symfony-contracts FQCN; the foundation FQCN silently no-ops — that bug killed this package's previous listener):

```php
public function boot(): void
{
    $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
    if ($dispatcher === null) {
        return;
    }
    $dispatcher->addListener(
        \Waaseyaa\Entity\Event\EntityEvents::PRE_SAVE->value,
        [$this->resolve(WorkflowStateGuard::class), 'onPreSave'],
    );
}
```

`DefaultWorkflows::EDITORIAL` = the spec's YAML block as a PHP const array (states draft/review/published/archived with flags, initial_state draft, 5 transitions with derived permissions). Seeding: in `boot()`, if `getStorage('workflow')->load('editorial')` is null, create+save `new Workflow(DefaultWorkflows::EDITORIAL)` (validate with `WorkflowValidator` first; log-and-skip on violations rather than crash boot — best-effort rule).

**`GuardWiringTest` (the wiring regression test — REQUIRED, this is a spec invariant):** boot a real kernel/container the way existing integration tests do (see `tests/Integration/` fixtures or the workflows package's existing integration tests), save a bound fixture entity through the real repository, and assert the guard actually fired (e.g. a born-published save by a permissionless context got floored to initial state). A unit test on the guard class does NOT satisfy this.

- [ ] **Step 1: Failing unit tests for the guard rules above** (one per rule branch, deny paths included).
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement guard + provider wiring + seed.**
- [ ] **Step 4: Failing wiring regression test → make it pass.**
- [ ] **Step 5: Run workflows suite + full Unit suite; expect PASS.**
- [ ] **Step 6: Commit** — `feat(#1920): PRE_SAVE workflow guard, provider wiring, default editorial seed (WP-1)`

### Task 1.9: Integration spine + spec truth-up + PR

**Files:**
- Create: `packages/workflows/tests/Integration/EditorialFlowTest.php`
- Modify: `docs/specs/content-workflow.md` (WP-1 row → landed; correct any drift between spec and as-built), `packages/workflows/README.md` (document the new engine surface; mark the legacy machinery "superseded, removal tracked as WP-5 / #1920")

- [ ] **Step 1: Integration spine test** — real SQLite (`DBALDatabase::createSqlite()`), revisionable fixture entity type bound to the seeded editorial workflow, real dispatcher + guard + TransitionService: create (forced draft + unpublished) → `submit_for_review` as an account with only that permission → `publish` DENIED for that account (assert reason `permission`) → `publish` as a publisher account → entity published → `archive` → unpublished. Assert audit entries exist for each fired transition (in-memory audit writer double).
- [ ] **Step 2: Run integration suite; expect PASS.** `./vendor/bin/phpunit packages/workflows/tests/`
- [ ] **Step 3: Full gates:** `set -o pipefail; composer phpstan && ./vendor/bin/phpunit --testsuite Unit && ./vendor/bin/phpunit --testsuite Integration && php bin/check-getquery-bindings && bin/check-package-layers`
- [ ] **Step 4: Spec + README truth-up commit** — `docs(#1920): content-workflow spec/README reflect WP-1 as built`
- [ ] **Step 5: Push + PR**

```bash
git push -u origin feat/cw-wp1-engine
gh pr create --title "feat(#1920): CW-v1 WP-1 — content-workflow engine core" \
  --body "$(printf '**Anchoring issue / plan:** #1920, docs/history/plans/2026-07-06-content-workflow-wp0-wp1.md\n\n## Summary\nEngine core per docs/specs/content-workflow.md: state flags + transition permissions + initial state on the Workflow config entity, WorkflowValidator, WorkflowBindingResolver, AccountContext (access) set by SessionMiddleware, TransitionService (validate→apply→events→audit), PRE_SAVE WorkflowStateGuard wired via the Symfony-contracts dispatcher FQCN with a wiring regression test, default editorial workflow seeded as data, integration spine test.\n\nRevision promotion (default_revision mechanics) is WP-2; group constraints are WP-3.\n')"
```

---

## Self-review notes (done at plan-writing time)

- **Spec coverage:** config schema (1.1–1.3), bindings (1.4), TransitionService + getAvailableTransitions (1.7), save-path guard + create-initial-state (1.8), events (1.6), permissions (1.2), default editorial as data (1.8), wiring regression + deny-path testing invariants (1.7/1.8/1.9), audit downward-import (1.7), WP-0 gate + unpublished default (0.1/0.2). NOT in these WPs by design: revision promotion (WP-2), group constraints (WP-3), API endpoints/admin UI (WP-4), cleanup (WP-5).
- **Known judgment calls an implementer must NOT re-decide:** permission name string; fail-closed deny reasons; guard's null-account-context = edge-legality-only; seeding is log-and-skip on invalid, never boot-crash.
- **Verify-don't-trust:** exact accessor names on `Workflow` (`getState`, `getTransitions`), the api create-test fixture shape, and how SessionMiddleware's deps are bound — confirmed to exist but signatures may have drifted; adjust code to match reality and note deviations in the PR body.
