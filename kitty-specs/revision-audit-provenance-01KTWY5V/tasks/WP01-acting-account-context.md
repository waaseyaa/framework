---
work_package_id: WP01
title: Acting-Account Context (access + wiring)
dependencies: []
requirement_refs:
- FR-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-revision-audit-provenance-01KTWY5V
base_commit: ca22f5cdeb5068b241dd5474fbfc46d96f55064b
created_at: '2026-06-12T04:20:13.466153+00:00'
subtasks:
- T001
- T002
- T003
- T004
shell_pid: "5860"
agent: "claude:fable-5:reviewer:reviewer"
history:
- date: '2026-06-12T03:32:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/access/src/
execution_mode: code_change
owned_files:
- packages/access/src/Context/AccountContextInterface.php
- packages/access/src/Context/RequestAccountContext.php
- packages/access/tests/Unit/Context/**
- packages/user/src/Middleware/SessionMiddleware.php
- packages/user/tests/**
- packages/foundation/src/Kernel/AbstractKernel.php
- packages/foundation/src/Kernel/HttpKernel.php
- packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php
- packages/foundation/src/Kernel/Bootstrap/ProviderRegistryKernelServices.php
tags: []
---

# WP01 — Acting-Account Context (access + wiring)

**Mission**: revision-audit-provenance-01KTWY5V | **Tracks**: #1644, #1645
**Requirements**: FR-002 | **Dependencies**: none
**Command**: `spec-kitty agent action implement WP01 --agent <name>`

## Objective

Create the request-scoped acting-account holder the entire mission hangs on: `AccountContextInterface` + `RequestAccountContext` in `packages/access` (research D1), set by `SessionMiddleware` on every HTTP request alongside the `_account` attribute, constructed exactly once per kernel and exposed everywhere downstream consumers need it — the repository-factory seam (WP02), the provider services bus (WP03's audit listeners), and the handler container (WP04's MCP endpoint).

Nothing in this WP records an author or an audit actor yet. After this WP, the acting account *travels*; WP02–WP04 read it.

## Context (read first)

- `research.md` D1 — the decision, the layer answer, and the three writer surfaces. Only the `SessionMiddleware` writer is this WP's scope; `McpEndpoint` and `AgentExecutor` writers belong to another WP.
- `data-model.md` "Acting account — three states" — account N / anonymous 0 / none null. The holder itself never coerces; it stores what it is given and returns `null` when unset.
- **No account-context service exists today** (verified: `rg "AccountContext|CurrentAccount" packages/` → only unrelated `_account`-attribute readers). The account travels exclusively as the `_account` request attribute set at `packages/user/src/Middleware/SessionMiddleware.php:61`.
- `packages/access` is L1 and owns `AccountInterface` (`packages/access/src/AccountInterface.php`) — the established anti-circularity rule (CLAUDE.md gotcha: "Access owns AccountInterface; Access must not depend on User"). The holder type-hints `AccountInterface`, never a concrete account class.
- `packages/user` already requires `waaseyaa/access` (SessionMiddleware imports `AccountInterface` today) — **no composer.json change needed in this WP**.
- Kernel precedent for cross-cutting repository collaborators: the alpha.204 validator is constructed once in `AbstractKernel::bootEntityTypeManager()` and captured by the repository factory closure (`packages/foundation/src/Kernel/AbstractKernel.php`, look for the `#1643` comment block and the `validator: $validator` pass). The Kernel/ classes carry the sanctioned cross-layer import exemption.
- Services bus: providers resolve unbound abstracts through `ServiceProvider::resolve()` → `KernelServicesInterface::get()` fallback (`packages/foundation/src/ServiceProvider/ServiceProvider.php:136-150`). The default implementation is the hardcoded if-chain in `packages/foundation/src/Kernel/Bootstrap/ProviderRegistryKernelServices.php` (which already imports L1 types — `Waaseyaa\Entity\EntityTypeManager` — so a typed `AccountContextInterface` arm is precedented). It is constructed at three sites: `ProviderRegistry::discoverAndRegister()` (`Bootstrap/ProviderRegistry.php:40`), `AbstractKernel::discoverAccessPolicies()` (`:478`), `AbstractKernel::bootScheduleEntries()` (`:505`).
- Handler container: `AbstractKernel::buildHandlerContainer()` has an explicit `$kernelBindings` map (`:778-799`) for kernel-owned services not bound by any provider — the place WP04's MCP endpoint resolution will find the context.
- `SessionMiddleware` is constructed positionally in `HttpKernel` (`packages/foundation/src/Kernel/HttpKernel.php:324-330`) — NOT auto-discovered for the HTTP pipeline. The new ctor param must be passed there or production HTTP requests never populate the context.

### Cross-WP seam (read carefully)

The plan wires the context into `EntityRepository` via the kernel repository factory. The receiving member on `EntityRepository` is **WP02's file** and does not exist when this WP merges. Do NOT pass a named constructor argument (`accountContext:`) to `new EntityRepository(...)` — it will not compile. Instead ship the forward seam described in T003: a `method_exists`-guarded `setAccountContext()` attach call, no-op until WP02 lands. Precedent for `method_exists` seams in this exact pipeline: `EntityRepository::loadRevision()` hydrates `revisionId`/`isCurrentRevision` via `method_exists` today. This adaptation is documented in `tasks.md` § "Cross-WP seam".

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| Holder pair in `packages/access/src/Context/` | FR-002 (resolved from request scope, no manual threading) | research D1; data-model.md "Acting account" |
| SessionMiddleware writer | FR-002; spec scenario 1 premise | data-model.md holder-writers table row 1 |
| Kernel single instance + bus/bindings exposure | FR-002 (one context every consumer agrees on) | research D1 "Layer answer"; plan Design Outline §1 |
| Repository-factory forward seam | FR-001 enabler (consumed by the entity-storage WP) | tasks.md "Cross-WP seam" |
| Null default everywhere | FR-001/FR-004 "absence is null, never 0" | data-model.md three-state table |

## Out of scope for this WP (do not touch)

- `packages/entity-storage/**` — actor resolution, SaveContext, EntityRepository receiver: the entity-storage WP.
- `packages/audit/**` — listener context params and provider resolution: the audit WP.
- `packages/mcp/**`, `packages/ai-agent/**` — the other two context writers: the agent/MCP WP.
- Any composer.json — this WP introduces zero manifest edges.
- `ConsoleKernel` — CLI contexts deliberately read null (the spec's "no actor context" state); nothing to wire.

## Subtasks

### T001 — `AccountContextInterface` + `RequestAccountContext`

**Files**: `packages/access/src/Context/AccountContextInterface.php`, `packages/access/src/Context/RequestAccountContext.php` (both NEW, namespace `Waaseyaa\Access\Context`)

1. Interface — exactly two methods (research D1):
   ```php
   interface AccountContextInterface
   {
       /** The account in whose name the current operation runs, or null when no acting context exists. */
       public function current(): ?AccountInterface;

       /** Set (or clear with null) the acting account for the current request/run scope. */
       public function set(?AccountInterface $account): void;
   }
   ```
   Mark the interface `@api` in the class-level PHPDoc (public extension point; dead-code gate convention). Docblock must state the three-state model (account N / anonymous 0 via `AnonymousUser` / null = no context) and the scoping contract: HTTP requests overwrite unconditionally; non-HTTP writers set/restore; CLI/queue/bootstrap read null unless something sets it.
2. `RequestAccountContext` — `final class`, implements the interface, single private nullable property, no constructor logic, no statics, no caching. `current()` returns the property; `set()` assigns it (including null = clear). This is a deliberately dumb mutable holder — resist adding stack/restore helpers; the set/restore discipline lives at the writer sites (D1).
3. `declare(strict_types=1)`; `use Waaseyaa\Access\AccountInterface;`. No other imports. Do not import anything from `packages/user` (circularity gotcha).

**Validation**: T004 unit tests; `composer phpstan`; `composer cs-check`.

### T002 — SessionMiddleware sets the context

**Files**: `packages/user/src/Middleware/SessionMiddleware.php`, `packages/foundation/src/Kernel/HttpKernel.php`

1. Add a trailing optional constructor param to `SessionMiddleware` (after `$trustedProxies`, keeping positional compatibility for every existing construction):
   ```php
   private readonly ?AccountContextInterface $accountContext = null,
   ```
2. In `process()`, mirror the account into the context wherever `_account` is settled — **both branches**:
   - The early-return branch (`$existingAccount instanceof AccountInterface && $existingAccount->isAuthenticated()`, around `:58-61`): call `$this->accountContext?->set($existingAccount)` before `return $next->handle($request)`. This branch fires when `BearerAuthMiddleware` (higher priority) already resolved an account — the context must mirror it too.
   - The main path: immediately alongside `$request->attributes->set('_account', $account)` (`:61`), call `$this->accountContext?->set($account)`.
   - Unconditional overwrite per request, never restore — HTTP requests are the outermost scope (data-model.md holder-writers table).
3. `HttpKernel` (`:324-330`): pass the kernel's shared instance as the new argument to the `new SessionMiddleware(...)` construction (use a named arg `accountContext:` for clarity). The instance comes from the AbstractKernel property/accessor added in T003.
4. Do not change priority, session handling, or account resolution logic. The diff to `process()` is two `?->set()` calls.

**Validation**: T004 middleware tests; existing `packages/user/tests/` suite stays green.

### T003 — AbstractKernel: shared instance + exposure

**Files**: `packages/foundation/src/Kernel/AbstractKernel.php`, `packages/foundation/src/Kernel/Bootstrap/ProviderRegistryKernelServices.php`, `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`

1. **One instance per kernel**: add a private property holding a `RequestAccountContext`, created before providers register (constructor or first-use `??=` behind the accessor — match the kernel's existing initialization style). Add a public accessor:
   ```php
   public function accountContext(): \Waaseyaa\Access\Context\AccountContextInterface
   ```
   (public: `HttpKernel` is a subclass but tests and entry points may need it; the kernel cross-layer exemption covers the L1 import).
2. **Repository-factory forward seam**: inside the repository factory closure in `bootEntityTypeManager()` (the closure that ends with `return new EntityRepository(...)` — find the `#1643` validator block as the landmark), capture the context and attach it after construction:
   ```php
   $repository = new EntityRepository(/* existing args unchanged */);
   // revision-audit-provenance-01KTWY5V WP01: forward seam — EntityRepository
   // gains setAccountContext() in WP02 of this mission; until then this is a
   // deliberate no-op (method_exists precedent: loadRevision() hydration).
   if (method_exists($repository, 'setAccountContext')) {
       $repository->setAccountContext($accountContext);
   }
   return $repository;
   ```
   Do NOT add a named constructor argument — see "Cross-WP seam" above. Keep the comment; WP02's reviewer uses it.
3. **Services bus arm** (so `AuditServiceProvider` can `resolveOptional(AccountContextInterface::class)` — consumed by another WP):
   - `ProviderRegistryKernelServices`: add an optional constructor param `private readonly ?AccountContextInterface $accountContext = null` (typed import is fine — the file already imports L1 `Waaseyaa\Entity\EntityTypeManager`) and a `get()` arm: `if ($abstract === AccountContextInterface::class) { return $this->accountContext; }`.
   - `ProviderRegistry::discoverAndRegister()`: add an optional trailing param `?AccountContextInterface $accountContext = null` and pass it through to the `ProviderRegistryKernelServices` construction at `:40`.
   - `AbstractKernel`: pass the shared instance at all three construction sites — the `discoverAndRegister` call (`:399` area) and the two inline `new ProviderRegistryKernelServices(...)` sites in `discoverAccessPolicies()` (`:478`) and `bootScheduleEntries()` (`:505`).
4. **Handler container binding**: in `buildHandlerContainer()`, add to `$kernelBindings`:
   ```php
   \Waaseyaa\Access\Context\AccountContextInterface::class =>
       static fn(\Psr\Container\ContainerInterface $c) => $kernel->accountContext(),
   ```
   This is the resolution path controller-resolved classes (MCP endpoint, another WP) will use.
5. Every exposure path must return the SAME instance — that is the whole point. No path may construct a second `RequestAccountContext`.

**Validation**: boot-level coverage comes via T004's kernel-light assertions plus, transitively, the WP02 kernel integration test. `composer phpstan` must be clean — note PHPStan understands the `method_exists` narrowing on a typed `EntityRepository` only if the method truly doesn't exist yet; if it complains about an always-false `method_exists`, suppress with the established inline-ignore style used elsewhere in the kernel (search `@phpstan-ignore` in `packages/foundation/src/`) and say so in your completion notes.

### T004 — Unit tests

**Files**: `packages/access/tests/Unit/Context/RequestAccountContextTest.php` (NEW), extend `packages/user/tests/Unit/` middleware coverage (locate the existing test with `rg -l "SessionMiddleware" packages/user/tests/`)

Holder tests (`RequestAccountContextTest`, namespace `Waaseyaa\Access\Tests\Unit\Context` — match the package's existing test namespace):

1. Fresh instance → `current()` is `null` (the CLI/bootstrap default state).
2. `set($account)` → `current()` returns the same object (identity, not equality).
3. `set(null)` clears → `current()` is `null` again.
4. Overwrite: `set($a); set($b)` → `current() === $b`.
5. An anonymous account (any `AccountInterface` stub with `id() === 0`) is stored and returned like any other — the holder performs no sentinel handling.

Use anonymous classes or stubs implementing `AccountInterface` (PHPUnit attribute style `#[Test]`, `#[CoversClass(RequestAccountContext::class)]`). Fixture guidance:

```php
private function account(int $id, bool $authenticated = true): AccountInterface
{
    return new class($id, $authenticated) implements AccountInterface {
        // implement id(), isAuthenticated(), and the remaining interface
        // members minimally — check AccountInterface for the full method list
        // and mirror how packages/access/tests/Unit/ fixtures already do this.
    };
}
```

PHPUnit `createMock()` is fine for `AccountInterface` (not final, not an intersection type), but the existing access tests' anonymous-class style is preferred for consistency.

Middleware tests (extend the existing SessionMiddleware test class, following its fixture style):

6. Request resolving an authenticated account → the injected context's `current()` is that account AND `_account` is set (both surfaces agree).
7. Request resolving the anonymous fallback → context holds the anonymous account (id 0), not null.
8. Pre-set authenticated `_account` (the early-return branch) → context mirrors the pre-set account.
9. Constructed WITHOUT the context param (legacy construction) → no error; `_account` behavior unchanged (the `?->` guard).

**Validation**:

```bash
./vendor/bin/phpunit packages/access/tests/ --no-progress
./vendor/bin/phpunit packages/user/tests/ --no-progress
composer phpstan
composer cs-check
bin/check-package-layers
```

## Edge cases & risks (from the plan premortem)

- **Stale actor in long-lived processes**: this WP's only writer (SessionMiddleware) overwrites unconditionally per request, which is the correct discipline for the outermost HTTP scope. The set/restore-in-`finally` discipline belongs to the non-HTTP writers in the agent/MCP WP — do NOT pre-build restore machinery into the holder for them (D1 keeps the holder dumb).
- **Anonymous is an actor**: `AnonymousUser` (id 0) flowing into the context is correct and required (spec edge case "anonymous web requests"). A reviewer seeing `set($anonymousUser)` should read it as a feature; only `null` means "nobody".
- **Account sentinel IDs** (CLAUDE.md gotcha): `AnonymousUser` id 0, `DevAdminAccount` id `PHP_INT_MAX`. The middleware's dev-fallback branch routes through the same `resolveAccount()` → context mirror; test 7's anonymous case covers the 0 path, and the dev fallback needs no special handling (it is just another account N).
- **`_account` vs `account`** (CLAUDE.md gotcha): every assertion about the request attribute must use `_account`. The context does not replace the attribute — both are set, forever; downstream HTTP code keeps reading `_account`, non-HTTP code reads the context.
- **PHPStan vs the forward seam**: `method_exists($repository, 'setAccountContext')` on a typed `EntityRepository` that lacks the method may be reported as always-false at this WP's point in time. If PHPStan flags it, prefer widening the local variable to `object` for the guard or the established inline-ignore style — never delete the seam. State the choice in completion notes so the entity-storage WP's reviewer knows what to expect.
- **Two contexts by accident**: the handler container caches resolved instances, the services bus serves the kernel's property, HttpKernel passes the same property — but a future refactor could diverge them. The accessor + property pattern (one construction site) is the guard; test it via identity assertions where practical.

## Definition of Done

- [ ] All four subtasks complete; `./vendor/bin/phpunit packages/access/tests/ packages/user/tests/` green.
- [ ] `composer phpstan` clean against baseline (no new baseline entries); `composer cs-check` clean.
- [ ] `bin/check-package-layers` green — this WP adds NO composer manifest edges (user → access already exists; foundation Kernel/ imports ride the entry-point exemption).
- [ ] `bin/check-dead-code` clean: the interface carries `@api`; `RequestAccountContext` is wired (middleware + kernel), so no `@api` needed on it.
- [ ] Exactly one `RequestAccountContext` per kernel, observable through `accountContext()`, the services bus, and the handler container — same instance via all three.
- [ ] The repository-factory seam is a guarded no-op with the WP02 hand-off comment in place; no named `accountContext:` constructor arg anywhere.
- [ ] Existing full middleware/user/access suites green unmodified; no changes outside `owned_files`.

## Reviewer guidance

- **Layer edge check is the highest-value assertion**: `packages/access` must gain no new imports beyond its own package + `AccountInterface`; `packages/user` must not import anything new beyond `Waaseyaa\Access\Context\AccountContextInterface`; run `bin/check-package-layers` yourself and eyeball the `use` blocks. The holder living in access (not entity, not foundation) is D1's reviewed decision — reject any relocation.
- Verify single-instance discipline: grep the diff for `new RequestAccountContext` — exactly one production construction site (the kernel). A second construction anywhere (middleware default, provider) silently forks the context and the mission's reads/writes stop agreeing.
- The early-return branch in `SessionMiddleware::process()` is the easy miss — confirm test 8 actually exercises a pre-set authenticated `_account` and asserts the context was populated.
- Confirm the forward seam: no `accountContext:` named arg to `EntityRepository`, `method_exists` guard present, hand-off comment references this mission. The WP merges compile-green standalone.
- The holder must stay dumb: reject stack/save-restore helpers, statics, or sentinel coercion inside `RequestAccountContext` — writers own scoping discipline (D1).

## How later WPs consume this (orientation, not work)

| Consumer | Path it uses | What breaks if you get it wrong |
|---|---|---|
| Entity-storage WP (`EntityRepository`) | the `method_exists('setAccountContext')` attach in the repository factory | revision authors silently null on every kernel-booted save |
| Audit WP (`AuditServiceProvider::boot()`) | `resolveOptional(AccountContextInterface::class)` → services-bus arm | audit listeners record null actors even in authenticated requests |
| Agent/MCP WP (`McpEndpoint`, `AgentExecutor`) | handler-container binding / provider resolution at construction sites | MCP/agent writes lose the initiator; context never set outside HTTP |
| Every HTTP request | `SessionMiddleware` mirror of `_account` | the entire mission records null for web traffic |

## Completion notes template (fill in before requesting review)

- Construction-site audit: paste `rg -n "new RequestAccountContext" packages/` output (must be exactly one production site + test fixtures).
- Same-instance proof: how you verified accessor / bus / handler container return the identical object (test name or manual check).
- PHPStan handling of the forward seam: clean as-written / inline-ignore used (which style, where).
- SessionMiddleware branches covered: list the two `?->set()` call locations by line.

## Activity Log

- 2026-06-12T03:32:00Z – spec-kitty.tasks – created
- 2026-06-12T04:20:15Z – claude:fable-5:implementer:implementer – shell_pid=25592 – Assigned agent via action command
- 2026-06-12T04:30:44Z – claude:fable-5:implementer:implementer – shell_pid=25592 – Ready for review
- 2026-06-12T04:32:10Z – claude:fable-5:reviewer:reviewer – shell_pid=5860 – Started review via action command
- 2026-06-12T04:34:35Z – claude:fable-5:reviewer:reviewer – shell_pid=5860 – Review passed: holder pair in packages/access imports only AccountInterface; single production RequestAccountContext construction (AbstractKernel:340 ??= accessor) serving HttpKernel middleware, repo-factory seam, services bus (all 3 sites), and handler container; SessionMiddleware mirrors _account in both branches with 4 new tests incl. early-return and legacy-construction; method_exists forward seam typed object, no accountContext: ctor arg, WP02 hand-off comment present; anonymous id-0 stored as-is (pinned). Gates: 463+7 tests green, phpstan clean, cs-check clean, check-package-layers OK, check-dead-code OK.
