# CSRF JSON Exemption (#468) & Node GraphQL Fields (#469) Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two framework blockers preventing Claudriel's GraphQL adoption: CSRF rejecting `application/json` POSTs, and Node's content fields missing from GraphQL schema.

**Architecture:** Two independent fixes. #468 adds `application/json` to CSRF exempt content types and marks the GraphQL route as CSRF-exempt. #469 adds missing `title`, `type`, `slug` field definitions to `NodeServiceProvider` and fixes the `uid` entity reference format.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Symfony HttpFoundation, webonyx/graphql-php

**Spec:** `docs/superpowers/specs/2026-03-17-csrf-json-and-node-graphql-fields-design.md`

---

### Task 1: CSRF exempts `application/json` (#468)

**Files:**
- Modify: `packages/user/src/Middleware/CsrfMiddleware.php:22`
- Modify: `packages/user/tests/Unit/Middleware/CsrfMiddlewareTest.php`

- [ ] **Step 1: Write the failing test — `application/json` POST bypasses CSRF**

Add to `CsrfMiddlewareTest.php` after the `postWithJsonApiContentTypeSkipsCsrf` test:

```php
#[Test]
public function postWithJsonContentTypeSkipsCsrf(): void
{
    $_SESSION['_csrf_token'] = 'valid-token';

    $request = Request::create('/graphql', 'POST', [], [], [], [], '{"query":"{ nodeList { items { id } } }"}');
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->middleware->process($request, $this->passthrough);

    $this->assertSame(200, $response->getStatusCode());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter postWithJsonContentTypeSkipsCsrf`
Expected: FAIL — `application/json` is not in the exempt list, returns 403.

- [ ] **Step 3: Add `application/json` to exempt content types**

In `CsrfMiddleware.php` line 22, change:
```php
private const CSRF_EXEMPT_CONTENT_TYPES = ['application/vnd.api+json'];
```
To:
```php
private const CSRF_EXEMPT_CONTENT_TYPES = ['application/vnd.api+json', 'application/json'];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter postWithJsonContentTypeSkipsCsrf`
Expected: PASS

- [ ] **Step 5: Run full CSRF test suite**

Run: `./vendor/bin/phpunit --filter CsrfMiddlewareTest`
Expected: All tests pass (no regressions).

- [ ] **Step 6: Commit**

```bash
git add packages/user/src/Middleware/CsrfMiddleware.php packages/user/tests/Unit/Middleware/CsrfMiddlewareTest.php
git commit -m "fix(#468): exempt application/json from CSRF validation"
```

---

### Task 2: GraphQL route CSRF exemption (#468)

**Files:**
- Modify: `packages/graphql/src/GraphQlRouteProvider.php:22`

- [ ] **Step 1: Add `->csrfExempt()` to GraphQL route**

In `GraphQlRouteProvider.php`, add `->csrfExempt()` after `->allowAll()`:

```php
RouteBuilder::create('/graphql')
    ->controller('graphql.endpoint')
    ->allowAll()
    ->csrfExempt()
    ->methods('GET', 'POST')
    ->build(),
```

- [ ] **Step 2: Run GraphQL tests to verify no regressions**

Run: `./vendor/bin/phpunit --filter GraphQl`
Expected: All GraphQL tests pass.

- [ ] **Step 3: Commit**

```bash
git add packages/graphql/src/GraphQlRouteProvider.php
git commit -m "fix(#468): mark GraphQL route as CSRF-exempt"
```

---

### Task 3: Add missing Node field definitions (#469)

**Files:**
- Modify: `packages/node/src/NodeServiceProvider.php:20-61`
- Modify: `packages/node/tests/Unit/NodeServiceProviderTest.php`

- [ ] **Step 1: Write the failing test — Node has title, type, slug field definitions**

Update the existing `node_entity_type_has_field_definitions` test in `NodeServiceProviderTest.php`:

```php
#[Test]
public function node_entity_type_has_field_definitions(): void
{
    $provider = new NodeServiceProvider();
    $provider->register();

    $fields = $provider->getEntityTypes()[0]->getFieldDefinitions();

    // Content fields
    $this->assertArrayHasKey('title', $fields);
    $this->assertSame('string', $fields['title']['type']);
    $this->assertTrue($fields['title']['required']);

    $this->assertArrayHasKey('type', $fields);
    $this->assertSame('string', $fields['type']['type']);
    $this->assertTrue($fields['type']['readOnly']);

    $this->assertArrayHasKey('slug', $fields);
    $this->assertSame('string', $fields['slug']['type']);

    // System fields
    $this->assertArrayHasKey('status', $fields);
    $this->assertArrayHasKey('promote', $fields);
    $this->assertArrayHasKey('sticky', $fields);
    $this->assertArrayHasKey('uid', $fields);
    $this->assertArrayHasKey('created', $fields);
    $this->assertArrayHasKey('changed', $fields);

    // uid reference uses top-level target_entity_type_id
    $this->assertSame('entity_reference', $fields['uid']['type']);
    $this->assertSame('user', $fields['uid']['target_entity_type_id']);
    $this->assertArrayNotHasKey('settings', $fields['uid']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter node_entity_type_has_field_definitions`
Expected: FAIL — `title`, `type`, `slug` keys missing; `uid` lacks `target_entity_type_id`.

- [ ] **Step 3: Add field definitions and fix uid reference**

In `NodeServiceProvider.php`, update the `fieldDefinitions` array. Add `title`, `type`, `slug` at the top; fix `uid` to use `target_entity_type_id`:

```php
fieldDefinitions: [
    'title' => [
        'type' => 'string',
        'label' => 'Title',
        'description' => 'The title of the content.',
        'required' => true,
        'weight' => 0,
    ],
    'type' => [
        'type' => 'string',
        'label' => 'Content type',
        'description' => 'The bundle (content type) machine name.',
        'required' => true,
        'readOnly' => true,
        'weight' => 1,
    ],
    'slug' => [
        'type' => 'string',
        'label' => 'URL slug',
        'description' => 'The URL-safe identifier for the content.',
        'required' => true,
        'weight' => 2,
    ],
    'status' => [
        'type' => 'boolean',
        'label' => 'Published',
        'description' => 'Whether the content is published.',
        'weight' => 10,
        'default' => 1,
    ],
    'promote' => [
        'type' => 'boolean',
        'label' => 'Promoted to front page',
        'description' => 'Whether the content is promoted to the front page.',
        'weight' => 11,
        'default' => 0,
    ],
    'sticky' => [
        'type' => 'boolean',
        'label' => 'Sticky at top of lists',
        'description' => 'Whether the content is sticky at the top of lists.',
        'weight' => 12,
        'default' => 0,
    ],
    'uid' => [
        'type' => 'entity_reference',
        'label' => 'Author',
        'description' => 'The user who authored this content.',
        'target_entity_type_id' => 'user',
        'weight' => 20,
    ],
    'created' => [
        'type' => 'timestamp',
        'label' => 'Authored on',
        'description' => 'The date and time the content was created.',
        'weight' => 30,
    ],
    'changed' => [
        'type' => 'timestamp',
        'label' => 'Last updated',
        'description' => 'The date and time the content was last updated.',
        'weight' => 31,
    ],
],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter node_entity_type_has_field_definitions`
Expected: PASS

- [ ] **Step 5: Run full Node test suite**

Run: `./vendor/bin/phpunit --filter NodeServiceProvider`
Expected: All 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/node/src/NodeServiceProvider.php packages/node/tests/Unit/NodeServiceProviderTest.php
git commit -m "fix(#469): add title, type, slug field definitions to Node and fix uid reference"
```

---

### Task 4: Verify GraphQL schema exposes Node fields (#469)

**Files:**
- Read-only verification against existing test infrastructure

- [ ] **Step 1: Run existing GraphQL schema tests**

Run: `./vendor/bin/phpunit --filter SchemaValidationTest`
Expected: All tests pass. These tests use a synthetic `article` entity type that already declares `title` — they confirm the pipeline works. With Node now declaring `title`/`type`/`slug`, the same pipeline will expose them.

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass, no regressions.

- [ ] **Step 3: Verify `type` is excluded from mutation inputs**

The existing `updateInputTypeExcludesReadOnlyFields` test in `SchemaValidationTest.php` (line 289) confirms the `readOnly` → excluded-from-inputs pipeline works. With `type` marked `readOnly: true`, it will be excluded from `NodeCreateInput` and `NodeUpdateInput` automatically.

No additional test needed — the existing generic test covers this behavior.
