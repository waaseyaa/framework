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

Requests to a registered type without the opt-in return a JSON:API `404` error
with code `entity_type_not_api_exposed`; the detail names the missing `api: true`
flag. Discovery links, workflow sub-routes, and generated OpenAPI paths use the
same exposure decision.

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
