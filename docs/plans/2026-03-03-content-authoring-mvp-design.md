# Content Authoring MVP Design

## Problem

The admin SPA CRUD shell is complete (forms, lists, API routes, 11 widgets, SSE auto-refresh) but two blockers prevent any content from being created or edited:

1. **No authenticated user** — `SessionMiddleware` always resolves to `AnonymousUser` (0 permissions). Every mutating API call returns 403.
2. **Schema only exposes entity keys** — `SchemaPresenter` only shows id/uuid/label/bundle. Node-specific fields (status, promote, sticky, uid, created, changed) and term fields (description, weight, parent_id) are invisible. `SchemaController` passes `[]` as field definitions.

## Smoke Test Findings (2026-03-03)

Tested end-to-end on localhost:3001 (Nuxt dev) + localhost:8081 (PHP built-in server):

- Dashboard: works, all 12 entity types display with nav grouping
- Node list (`/node`): works but heading shows "node" not "Content" (cosmetic, out of scope)
- Node create form: only shows "Title" field — no status, type, uid
- Submit create form: 403 "Access denied for creating entity of type 'node'"
- API `GET /api/node`: returns empty array (works, no access check on list)
- API `POST /api/node`: 403 (anonymous user has no `create * content` permission)
- Schema API `GET /api/schema/node`: only 4 properties (nid, uuid, title, type)
- SQLite node table: only entity-key columns; non-key fields stored in `_data` JSON blob (functional)

## Fix 1: Dev Auto-Admin

### Approach
When PHP's built-in server is detected (`PHP_SAPI === 'cli-server'`), resolve to a dev admin account instead of `AnonymousUser` when no session exists.

### Why `cli-server` detection
- Built-in server is inherently dev-only (never used in production)
- No env var to forget to unset
- No risk of accidentally shipping admin bypass

### Components
- `DevAdminAccount` — new class in `packages/user/src/` implementing `AccountInterface`
  - `id()` → 1
  - `hasPermission()` → always true
  - `getRoles()` → `['administrator']`
  - `isAuthenticated()` → true
- `SessionMiddleware` — add optional `?AccountInterface $devFallback = null` constructor param
  - When no session uid exists and `$devFallback` is non-null, return it instead of `AnonymousUser`
- `public/index.php` — pass `new DevAdminAccount()` to `SessionMiddleware` when `PHP_SAPI === 'cli-server'`

## Fix 2: Entity Field Definitions

### Approach
Add `getFieldDefinitions(): array` to `EntityTypeInterface`. Each entity type declares its editable fields with metadata. `SchemaController` passes these to `SchemaPresenter` (which already supports them).

### Field definition format
Uses the existing format `SchemaPresenter::present()` accepts:
```php
[
    'status' => [
        'type' => 'boolean',
        'label' => 'Published',
        'description' => 'Whether the content is published.',
        'weight' => 10,
    ],
]
```

### Entity types to update

**Node** (`packages/node/`):
- `status` — boolean, "Published", weight 10
- `promote` — boolean, "Promoted to front page", weight 11
- `sticky` — boolean, "Sticky at top of lists", weight 12
- `uid` — entity_reference (target: user), "Author", weight 20
- `created` — timestamp, "Authored on", weight 30
- `changed` — timestamp, "Last updated", weight 31, readOnly

**TaxonomyTerm** (`packages/taxonomy/`):
- `description` — text, "Description", weight 5
- `weight` — integer, "Weight", weight 10
- `parent_id` — entity_reference (target: taxonomy_term), "Parent term", weight 15
- `status` — boolean, "Published", weight 20

**TaxonomyVocabulary** (`packages/taxonomy/`):
- `description` — text, "Description", weight 5
- `weight` — integer, "Weight", weight 10

**User** (`packages/user/`):
- `status` — boolean, "Active", weight 10
- `mail` — email, "Email address", weight 5
- `created` — timestamp, "Member since", weight 20, readOnly

### SchemaController change
```php
$schema = $this->schemaPresenter->present(
    $entityType,
    $entityType->getFieldDefinitions(),  // was: []
    $entity,
    $this->accessHandler,
    $this->account,
);
```

### EntityType default
`getFieldDefinitions()` returns `[]` in base `EntityType` class so existing entity types without field definitions still work unchanged.

## Out of Scope (Future Work)

- Page headings using entity labels instead of machine names
- SQL columns for frequently queried fields (status, uid, created)
- Missing tables (taxonomy_term, media, media_type)
- Client-side form validation
- Login/logout endpoint
- Bundle selection widget on create forms
