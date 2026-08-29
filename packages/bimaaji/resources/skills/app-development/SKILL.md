---
name: waaseyaa-app-development
description: Use when building features in any application built on the waaseyaa framework — entity types, service providers, controllers, access policies, ingestion adapters, or deployment configs. Triggers on app-level code that must follow framework conventions.
---

# Building Applications on Waaseyaa

## Overview

This skill ensures consistent, framework-compliant application development across all apps built on waaseyaa. It provides the canonical patterns, a framework-or-app decision framework, and an anti-duplication checklist.

## When to Use

- Adding entity types, service providers, controllers, access policies
- Wiring ingestion pipelines or deployment configs
- Any time you're writing app-level code that interacts with waaseyaa APIs

## Anti-Duplication Checklist

**Before writing ANY new code, complete these checks:**

1. Search waaseyaa specs: does the framework already provide this?
   - `rg -n "<capability-or-symbol>" docs/specs/` in the framework repo, or browse `docs/specs/` from the orchestration table in `CLAUDE.md`
2. Search sibling apps: has minoo or claudriel already solved this?
   - Grep `/home/fsd42/dev/minoo/src/` and `/home/fsd42/dev/claudriel/src/`
   - Check their specs in `docs/specs/`
3. If prior art exists:
   - Same pattern needed? → Follow the existing implementation
   - Both apps need it? → Nominate for framework extraction (use `waaseyaa:framework-extraction` skill)
   - App-specific variation? → Implement locally but document why it diverges

**Skipping this checklist is a red flag.** If you think "this is obviously app-specific," check anyway.

## Framework-or-App Decision

| Signal | Location |
|--------|----------|
| Two apps need it | Framework package |
| Extends a framework extension point (custom entity, policy, route) | App code |
| Domain-specific business logic, no reuse | App code |
| Infrastructure (caching, deployment, middleware pattern) | Framework candidate |
| Could be useful for ANY waaseyaa app | Framework candidate |

When uncertain, default to app code. Extract to framework later when the second app needs it.

## Canonical Patterns

### Entity Class

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Goal extends ContentEntityBase
{
    protected string $entityTypeId = 'goal';
    protected array $entityKeys = [
        'id' => 'gid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        // Set defaults before parent constructor
        $values += [
            'status' => 'draft',
        ];
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

**Rules:**
- Always `final class`
- Always `declare(strict_types=1)`
- Constructor takes `(array $values = [])` only
- Hardcode `entityTypeId` and `entityKeys` as protected properties
- Pass `$this->entityTypeId` to parent (not a string literal)
- Set defaults via `$values +=` before parent call
- Entity keys: `id` (unique short key), `uuid`, `label` (human-readable field)

### EntityType Registration (Service Provider)

```php
public function register(): void
{
    $this->entityType(new EntityType(
        id: 'goal',
        label: 'Goal',
        class: Goal::class,
        keys: ['id' => 'gid', 'uuid' => 'uuid', 'label' => 'title'],
        group: 'planning',
        fieldDefinitions: [
            'description' => ['type' => 'text_long', 'label' => 'Description'],
            'status' => ['type' => 'string', 'label' => 'Status'],
            'due_date' => ['type' => 'datetime', 'label' => 'Due Date'],
        ],
    ));
}
```

**Rules:**
- Use named constructor parameters
- Always include `fieldDefinitions` — they drive admin UI, JSON Schema, and validation
- Group related entity types with `group:`
- One provider per domain (not one giant provider for all types)
- `register()` = DI bindings + entity types. `boot()` = event subscriptions, Twig globals, cache warming

### Access Policy

```php
<?php

declare(strict_types=1);

namespace App\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\User\AccountInterface;

#[PolicyAttribute(entityType: 'goal')]
final class GoalAccessPolicy implements AccessPolicyInterface
{
    public function access(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        return match ($operation) {
            'view' => AccessResult::allowed(),
            default => $account->hasPermission('administer goals')
                ? AccessResult::allowed()
                : AccessResult::neutral(),
        };
    }
}
```

**Rules:**
- Always use `#[PolicyAttribute(entityType: '...')]` — auto-discovery depends on it
- Type-hint `AccountInterface`, never `mixed`
- Return `AccessResult` — `::allowed()`, `::neutral()`, `::forbidden()`
- Add `FieldAccessPolicyInterface` (intersection type) only if field-level control needed
- Entity-level: `isAllowed()` (deny unless granted). Field-level: `!isForbidden()` (allow unless denied)

### Controller

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\SsrResponse;
use Waaseyaa\User\AccountInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

final class GoalController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function list(
        array $params,
        array $query,
        AccountInterface $account,
        HttpRequest $request,
    ): SsrResponse {
        $storage = $this->entityTypeManager->getStorage('goal');
        $entities = $storage->loadMultiple();
        // Return appropriate response (JSON API or SSR)
    }
}
```

**Rules:**
- Type-hint `AccountInterface $account`, not `mixed $account = null`
- Controller signature: `(array $params, array $query, AccountInterface $account, HttpRequest $request)`
- Use `EntityTypeManager::getStorage()` to access entity storage
- For JSON API: use `ResourceSerializer` with paired nullable `?EntityAccessHandler` + `?AccountInterface`

### Route Registration

```php
public function routes(): void
{
    $this->route('goal.list', '/api/goals', GoalController::class, 'list')
        ->methods(['GET'])
        ->option('_permission', 'access content');

    $this->route('goal.show', '/api/goals/{gid}', GoalController::class, 'show')
        ->methods(['GET'])
        ->option('_permission', 'access content');
}
```

**Rules:**
- Access via route options: `_public`, `_permission`, `_role`, `_gate`
- `_gate` for entity-level access (defers to AccessPolicy)
- `_public` for unauthenticated access
- `_permission` for permission-based access

## Compliance Checklist

When reviewing app code, verify:

| Check | Pass Criteria |
|-------|--------------|
| Entity constructor | Takes `(array $values = [])`, hardcodes entityTypeId/Keys |
| Entity base class | Extends `ContentEntityBase` or `ConfigEntityBase` |
| Entity class | `final class` with `declare(strict_types=1)` |
| EntityType registration | Named params, includes fieldDefinitions |
| Provider separation | register() for DI/entities, boot() for events/globals |
| Provider scope | One per domain, not one monolithic provider |
| Access policy | `#[PolicyAttribute]`, implements `AccessPolicyInterface` |
| Controller typing | `AccountInterface $account`, not `mixed` |
| Route access | Uses `_public`/`_permission`/`_role`/`_gate` options |
| Anti-duplication | Checked framework and sibling apps before implementing |

## Red Flags

These rationalizations indicate the checklist is being bypassed:

| Thought | Reality |
|---------|---------|
| "This is obviously app-specific" | Check anyway — you might find prior art |
| "I'll extract it later if needed" | Document the decision now so it's traceable |
| "The other app does it differently" | That's a divergence — document why |
| "This is urgent, skip the checks" | Urgency doesn't excuse non-compliance |
| "It's just a small helper" | Small helpers duplicate fastest |

## Common Mistakes

- Using `mixed $account` instead of `AccountInterface` — breaks type safety and access checking
- Omitting `fieldDefinitions` — admin UI and JSON Schema can't discover fields
- Registering all entity types in one provider — makes code hard to navigate and test
- Skipping `#[PolicyAttribute]` — auto-discovery silently fails, policy never activates
- Not running `waaseyaa optimize:manifest` after adding providers/policies
- Hardcoding entity type string in constructor instead of using `$this->entityTypeId`
