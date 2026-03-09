# Waaseyaa Application Directory Convention (v1.0)

Every Waaseyaa application MUST follow this directory structure:

## Directory Structure

```
src/
  Access/        → Authorization policies
  Controller/    → HTTP controllers (thin orchestration)
  Domain/        → Domain logic grouped by bounded context
  Entity/        → ORM entities (pure data models)
  Ingestion/     → Inbound data pipelines (files, email, APIs)
  Provider/      → Service providers (bootstrapping, DI, routing)
  Search/        → Search providers, autocomplete, indexing
  Seed/          → Seeders for dev/local bootstrap
  Support/       → Cross-cutting utilities (ValueObjects, helpers)
```

## Domain Rules

Any folder representing a bounded context (e.g., Geo, DayBrief, Pipeline)
must be placed under `src/Domain/<ContextName>/`.

Domain folders may contain:

- `Service/` — domain services and orchestrators
- `ValueObject/` — immutable value objects
- `Workflow/` — multi-step domain workflows
- `Assembler/` — data assemblers and composers
- `Ranker/` — ranking and scoring logic
- `Mapper/` — domain-level data mappers

Only create subfolders as needed — do not pre-create empty ones.

## Support Rules

Cross-cutting helpers that are not specific to a single bounded context
must be placed in `src/Support/`. Examples:

- Validators
- Slug generators
- Normalizers
- Distance calculators
- Date/time utilities

## Ingestion Rules

`Ingestion/` is strictly for inbound data normalization and ingestion
pipelines (files, email, APIs). Do not place domain logic here.

## Controller Rules

Controllers must remain thin and contain no business logic. They
orchestrate calls to domain services and return responses.

## Provider Rules

`Provider/` is the only place where the framework is extended. Service
providers handle bootstrapping, dependency injection, entity type
registration, and route definitions.

## Namespace Rules

- Namespaces MUST match the PSR-4 directory structure
- Root namespace is defined in `composer.json` autoload
- After moving files, update namespaces in the file AND all references

### Examples

```php
// Entity
namespace App\Entity;

// Domain service
namespace App\Domain\Geo\Service;

// Domain value object
namespace App\Domain\Geo\ValueObject;

// Support utility
namespace App\Support;

// Ingestion pipeline
namespace App\Ingestion;

// Access policy
namespace App\Access;
```
