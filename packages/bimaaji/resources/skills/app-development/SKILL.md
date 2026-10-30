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
   - In a Composer consumer, search `vendor/waaseyaa/framework/docs/specs/` when that installed distribution is present. In a Framework checkout, search `docs/specs/` and use `AGENTS.md` for subsystem routing.
   - A split-package-only consumer may not ship the full specs. Report that limitation and inspect the installed package source/public contracts; do not assume a Framework checkout exists.
2. Search sibling apps: has minoo or claudriel already solved this?
   - Only search sibling checkouts made available within the authorized workspace, including their `src/` and `docs/specs/` trees.
   - Ordinary consumers do not include maintainer sibling apps. If absent, record prior art as unavailable and proceed with available framework contracts. Do not search outside the workspace or invent findings.
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

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

#[ContentEntityType(id: 'goal', label: 'Goal')]
#[ContentEntityKeys(id: 'gid', uuid: 'uuid', label: 'title')]
final class Goal extends ContentEntityBase
{
    protected string $entityTypeId = 'goal';
    protected array $entityKeys = [
        'id' => 'gid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    // This example deliberately exposes these nonsensitive planning fields.
    // Choose Public, Protected or Internal for each real application's fields.
    #[Field(label: 'Title', required: true, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'text_long', label: 'Description', read: FieldReadLevel::Public)]
    public string $description = '';

    #[Field(label: 'Status', default: 'draft', read: FieldReadLevel::Public)]
    public string $status = 'draft';

    #[Field(type: 'datetime', label: 'Due Date', read: FieldReadLevel::Public)]
    public ?\DateTimeImmutable $due_date = null;

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
    $this->entityType(EntityType::fromClass(Goal::class, group: 'planning'));
}
```

**Rules:**
- Import `App\Entity\Goal` and `Waaseyaa\Entity\EntityType` in the `ServiceProvider` subclass.
- Declare content metadata with `#[ContentEntityType]`, `#[ContentEntityKeys]` and typed `#[Field]` properties, then register `EntityType::fromClass()`.
- Field metadata drives admin UI, JSON Schema and validation. Declare a reviewed `read:` level on each field. Neither `fieldDefinitions:` nor the internal `_fieldDefinitions:` constructor slot is an application registration API.
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
use Waaseyaa\Access\AccountInterface;

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

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->appliesTo($entityTypeId) && $account->hasPermission('administer goals')
            ? AccessResult::allowed()
            : AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'goal';
    }
}
```

**Rules:**
- Always use `#[PolicyAttribute(entityType: '...')]` — auto-discovery depends on it
- Implement all three methods: `access()`, `createAccess()` and `appliesTo()`.
- Import `Waaseyaa\Access\AccountInterface`, never `mixed`. Kernel access decisions supply an immutable authorization principal; do not pass a live User entity as the decision account.
- Return `AccessResult` — `::allowed()`, `::neutral()`, `::forbidden()`
- Add `FieldAccessPolicyInterface` (intersection type) only if field-level control needed
- Entity-level: `isAllowed()` (deny unless granted). Field-level: `!isForbidden()` (allow unless denied)

### Controller

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Waaseyaa\Access\AccountInterface;
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
    ): JsonResponse {
        // Minimal response demonstrating the controller signature.
        // Before returning entity values, enforce entity access and use a
        // framework serializer/presenter with this request's principal.
        return new JsonResponse(['entity_type' => 'goal']);
    }
}
```

**Rules:**
- Type-hint `AccountInterface $account`, not `mixed $account = null`
- Controller signature: `(array $params, array $query, AccountInterface $account, HttpRequest $request)`
- Use `EntityTypeManager::getRepository()` for application reads and writes through the canonical repository lifecycle. Direct storage drivers belong to reviewed framework composition, not application persistence.
- For JSON API: use `ResourceSerializer` with paired nullable `?EntityAccessHandler` + `?AccountInterface`

### Route Registration

```php
public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $entityTypeManager): void
{
    $router->addRoute('goal.list', \Waaseyaa\Routing\RouteBuilder::create('/api/goals')
        ->controller([\App\Controller\GoalController::class, 'list'])
        ->methods('GET')
        ->requirePermission('access content')
        ->build());
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
| EntityType registration | Attribute-derived `EntityType::fromClass()`, classified `#[Field]` properties |
| Provider separation | register() for DI/entities, boot() for events/globals |
| Provider scope | One per domain, not one monolithic provider |
| Access policy | `#[PolicyAttribute]`, implements all three `AccessPolicyInterface` methods |
| Controller typing | `AccountInterface $account`, not `mixed` |
| Route access | Uses `_public`/`_permission`/`_role`/`_gate` options |
| Anti-duplication | Checked available framework contracts and authorized prior art; records unavailable references |

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
- Passing `fieldDefinitions:` or `_fieldDefinitions:` to `EntityType` instead of declaring attributes and using `EntityType::fromClass()`
- Registering all entity types in one provider — makes code hard to navigate and test
- Skipping `#[PolicyAttribute]` — auto-discovery silently fails, policy never activates
- Not running `waaseyaa optimize:manifest` after adding providers/policies
- Hardcoding entity type string in constructor instead of using `$this->entityTypeId`
