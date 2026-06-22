# Quickstart: Verifying Revision & Audit Provenance

**Mission**: revision-audit-provenance-01KTWY5V

Reviewer's hands-on script — each step maps to an acceptance scenario in [spec.md](spec.md).

## 1. Revision author recorded and readable (scenarios 1–2)

```bash
./vendor/bin/phpunit tests/Integration/Provenance/ --no-progress
./vendor/bin/phpunit packages/entity-storage/tests/Integration/RevisionAuthor/ --no-progress
```

Or by hand in a booted app: authenticate, save a revisionable entity, then:

```php
$rev = $repository->loadRevision($id, $revisionId);
$rev->revisionMetadata()->revisionAuthor;   // === your account uid (int)
```

CLI/bootstrap save (no session) → `revisionAuthor === null`, never `0`. Anonymous web write → `0`. Explicit override: `$repository->save($e, context: SaveContext::default()->withActorUid(null))` → null author inside an authenticated request.

## 2. Audit actor is the session account, not the entity's uid (scenarios 3–4)

```bash
./vendor/bin/phpunit packages/audit/tests/ --no-progress
```

By hand: as user A, save a node whose `uid` field is user B; then `SELECT actor_uid, account_uid FROM audit_event ORDER BY id DESC LIMIT 1` → `actor_uid = A`. A kernel-bootstrap write → `actor_uid IS NULL` (and `account_uid = 0`, legacy). An agent tool run by account N → `agent.tool_execute` row with `actor_uid = N` (not 0).

## 3. Publish-pointer moves are audited (scenario 5)

```php
$repository->setPublishedRevision($id, 2);
```

→ one `revision.publish` audit row: `actor_uid` = your account, attributes carry `{entity_id, operation: "publish", from_revision_id, to_revision_id: 2}`. `setCurrentRevision()` → `revision.revert`. Query: `GET /api/audit/events?filter[kind]=revision.publish`.

## 4. MCP dispatch event fires (scenario 6)

```bash
./vendor/bin/phpunit packages/mcp/tests/ --no-progress
```

By hand: POST a `tools/list` or `tools/call` JSON-RPC body to `/mcp` with a valid bearer token → one `mcp.dispatch` audit row with the method, a params *hash* (never raw params), and the bearer account's uid. A 401 or parse-error request produces no row. (The #1635/#1636 transport bugs are out of scope — the event fires from the seam regardless.)

## 5. Raw-SQL append-only guard (scenario 7)

```bash
./vendor/bin/phpunit packages/audit/tests/Integration/AuditImmutabilityTest.php --no-progress
```

By hand against the decorator: `$auditDb->query('UPDATE audit_event SET outcome = ?', ['denied'])` → `\LogicException` (same message as the builder guard). `DELETE`/`DROP`/`ALTER` likewise. Still passing: `SELECT … WHERE attributes LIKE '%delete%'` through the decorator, and `bin/waaseyaa audit:prune --older-than=P30D --dry-run` (prune resolves the raw database — untouched).

## 6. Upgrade path: pre-existing tables (scenario 8)

The migration-shaped test in `RevisionAuthorTest` builds a pre-mission revision table with rows, boots schema sync, and asserts: `revision_author` column added additively, old revisions read back `null` author, new revisions authored. Same shape for `audit_event` → `actor_uid` (pre-upgrade rows null).

## 7. Gates

```bash
composer verify          # suite + phpstan + composer policy + dead-code + getQuery gate
bin/check-package-layers # the new audit → access edge is L1→L1, must pass
```

CHANGELOG check: `[Unreleased]` (C-003 — not a pre-stamped alpha.205 heading) covers the `revision_author` column, `actor_uid` + null-vs-0 semantics, the `AuditEventDescriptor` `int → ?int` widening, the two new audit kinds + `RevisionPointerMovedEvent`, the MCP dispatch event, and the raw-SQL guard. Spec docs updated in the same PR: `revision-system-unified.md` (author column + dormant-dialect retirement, FR-009), `ocap-audit-log.md`, `mcp-endpoint.md`, `access-control.md`.
