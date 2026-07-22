# Entity-type JSON:API exposure

Starting with `0.1.0-alpha.266`, registering an entity type no longer enables
generic `/api/{entity_type}` CRUD routes by itself. Exposure is an explicit
capability and defaults to `false`.

Attributed content entities that intentionally use the generic JSON:API surface
must opt in on their canonical metadata:

```php
#[ContentEntityType(id: 'event', label: 'Event', api: true)]
final class Event extends ContentEntityBase
{
}
```

Imperatively registered types use the equivalent constructor flag:

```php
new EntityType(
    id: 'event_type',
    label: 'Event type',
    class: EventType::class,
    api: true,
);
```

Applications may narrow those declarations with an exact closed-world list:

```php
'api' => [
    'entity_type_allowlist' => ['event', 'event_type'],
],
```

When the key is absent, declaration-only behavior is unchanged. When present,
only registered, declared-`api: true`, exact listed ids are exposed; `[]`
suppresses every generic entity route. Unknown, duplicate, malformed, stale, or
declared-false entries fail boot. The list is intentionally deployment/install-
shape specific: a full installation and a minimal installation should use
different lists, and reusing the full list after removing a package is expected
to fail rather than silently ignore stale ids.

Anonymous and authenticated API requests to registered-but-unexposed types are
ordinary not-found responses, byte-identical to unregistered types. The complete
installed-type catalogue is available only to administrators at
`GET /api/entity-types`. CRUD, field auto-save, translations, workflow sub-
routes, discovery, entity schema, and OpenAPI share the effective decision.
Query/include relationship traversal into a suppressed type fails as an unknown
path before storage.

### Package declaration downgrade

Changing a shipped type from `api: true` to `api: false` can make a consumer's
strict allowlist fail boot. A framework/package release making that change must
carry a consumer-breaking `[Unreleased]` changelog entry naming the type and
upgrade guidance instructing consumers to remove the id before or with the
dependency upgrade. The stale entry is not ignored: strict failure is the guard
against an operator believing a route remains exposed when its package withdrew
support.

## In-house consumer migration

The pre-flip inventory is recorded on framework issue #2043. Minoo currently
registers 23 application-defined types:

`dictionary_entry`, `example_sentence`, `word_part`, `speaker`, `ingest_log`,
`featured_item`, `dialect_region`, `group`, `group_type`, `cultural_group`,
`community`, `elder_support_request`, `contributor`, `event`, `event_type`,
`game_session`, `daily_challenge`, `crossword_puzzle`, `post`, `saved_word`,
`translation_memory`, `tm_gap_log`, and `tm_backlog`.

Classify those types in the Minoo repository and add `api: true` only where its
generic admin surface should continue constructing `/api/{entityType}` requests.
Minoo's migration is a separate project follow-up; it is not part of the
framework release.

The `waaseyaa-feeds` types `feed_source`, `feed_item`, and `fetch_log` are
internal persistence definitions and should remain at the default `api: false`.
