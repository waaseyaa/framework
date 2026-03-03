# Access Policy Wiring Design

## Problem

`POST /api/node_type` returns 403 because `EntityAccessHandler` is constructed with an empty policy array in `public/index.php:358`. No policies are evaluated, so `checkCreateAccess()` returns `neutral`, which is denied under entity-level deny-unless-granted semantics. This affects all entity types, not just `node_type`.

## Root Cause

```php
$accessHandler = new EntityAccessHandler([]);
```

Existing policies (`NodeAccessPolicy`, `TermAccessPolicy`) exist as classes but are never wired in. Config entity types (`node_type`, `taxonomy_vocabulary`, `media_type`, `workflow`, `pipeline`) have no policy at all.

## Solution

### 1. ConfigEntityAccessPolicy

New class in `packages/access/src/ConfigEntityAccessPolicy.php`:

- Implements `AccessPolicyInterface`
- Constructor accepts `string[] $entityTypeIds` — the config entity types it covers
- `appliesTo()`: returns true if entity type is in the list
- `access()`: returns `allowed` if account has `administrator` role, `neutral` otherwise
- `createAccess()`: same logic
- Admin check: `in_array('administrator', $account->getRoles(), true)`

Config entity types covered: `node_type`, `taxonomy_vocabulary`, `media_type`, `workflow`, `pipeline`.

### 2. Wire policies in index.php

Replace `new EntityAccessHandler([])` with:

```php
$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new ConfigEntityAccessPolicy([
        'node_type',
        'taxonomy_vocabulary',
        'media_type',
        'workflow',
        'pipeline',
    ]),
]);
```

### 3. Testing

Unit tests for `ConfigEntityAccessPolicy`:
- `appliesTo()` true/false for listed/unlisted types
- `access()` allowed for admin, neutral for non-admin
- `createAccess()` allowed for admin, neutral for non-admin

### Out of scope

- `user` and `media` entity types — need richer per-bundle policies, not admin-only
- Gate system / PolicyAttribute — separate concern, not involved here
- PackageManifest auto-discovery of `AccessPolicyInterface` — future improvement
