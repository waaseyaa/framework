# waaseyaa/northcloud

North Cloud client, content sync, and search provider for Waaseyaa applications.

## What this package gives you

- **`NorthCloudClient`** — HTTP client for the North Cloud REST API (search, people, band office, dictionary, crawl jobs).
- **`NorthCloudCache`** — SQLite-backed response cache.
- **`NorthCloudSearchProvider`** — Implements `Waaseyaa\Search\SearchProviderInterface` so NC becomes a drop-in search backend.
- **`NcSyncService` + `NcSyncWorker` + `northcloud:sync` command** — Generic content ingestion orchestrator that pulls NC search hits and persists them as Waaseyaa entities.
- **`NcHitToEntityMapperInterface`** — The extension seam. Implement one of these per target entity type in your app; the package handles everything else.

## Installation

```bash
composer require waaseyaa/northcloud
```

Publish the config (optional):

```bash
./bin/waaseyaa config:publish northcloud
```

Set env vars:

```env
NORTHCLOUD_BASE_URL=https://api.northcloud.one
NORTHCLOUD_API_TOKEN=              # only needed for authenticated endpoints
```

## Usage: hooking up content sync in your app

Implement one mapper per entity type you want to populate from North Cloud:

```php
use Waaseyaa\NorthCloud\Sync\NcHitToEntityMapperInterface;

final class NcHitToKnowledgeItemMapper implements NcHitToEntityMapperInterface
{
    public function entityType(): string
    {
        return 'knowledge_item';
    }

    public function dedupField(): string
    {
        return 'source_url';
    }

    public function supports(array $hit): bool
    {
        $topics = $hit['topics'] ?? [];
        return is_array($topics) && in_array('indigenous', $topics, true);
    }

    public function map(array $hit): array
    {
        return [
            'title' => (string) ($hit['title'] ?? ''),
            // External HTML must be sanitized here against the destination
            // field's allowlist before it is persisted.
            'body' => $this->htmlSanitizer->sanitize(
                (string) ($hit['snippet'] ?? $hit['body'] ?? ''),
            ),
            'source_url' => (string) ($hit['url'] ?? ''),
            // ...app-specific fields
        ];
    }
}
```

`NcHitToEntityMapperInterface::map()` is the trust boundary for North Cloud
content. The sync service cannot safely apply one generic sanitizer because each
destination field owns a different markup contract. Mapper implementations must
sanitize external HTML before returning rendered or rich-text field values; plain
text and identifiers should be validated/coerced for their destination types.

Register the mapper in your `AppServiceProvider`:

```php
$mapperRegistry = $container->get(MapperRegistry::class);
$mapperRegistry->register(new NcHitToKnowledgeItemMapper(/* deps */));
```

Run a sync:

```bash
./bin/waaseyaa northcloud:sync --limit=20
./bin/waaseyaa northcloud:sync --dry-run
./bin/waaseyaa northcloud:sync --since=2026-01-01
```

## Architecture

```
  NC API
    ↓
NorthCloudClient  ──►  NorthCloudCache (SQLite)
    ↓
NcSyncService  ──►  MapperRegistry  ──►  your NcHitToEntityMapperInterface
    ↓
EntityTypeManager  ──►  persisted entities
```

One NC hit may produce multiple entities — every registered mapper whose `supports()` returns true gets to create one.

## Status

v0.1.0-alpha — API surface not yet stable. See [waaseyaa/framework#TBD](https://github.com/waaseyaa/framework/issues) for rollout plan.
