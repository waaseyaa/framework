<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\Preview\PreviewLinkService;

/**
 * Registers the full bundle-scoped content tool set under app-chosen stable
 * names — e.g. prefix `article` yields `article.list`, `article.get`,
 * `article.createDraft`, `article.updateDraft`, `article.preview`,
 * `article.publish`, `article.unpublish`, `article.revisions`,
 * `article.rollback`, plus `asset.upload` / `asset.get` when an asset store
 * is supplied.
 *
 * Every tool is registered `destructive: true` under the descriptor's publish
 * capability, so the set is structurally absent from the public `/mcp`
 * registry and reachable only through the authenticated write tier whose
 * capability allowlist includes it. Input schemas are derived from the
 * descriptor's writable fields (`additionalProperties: false`); every
 * mutation requires an `idempotency_key`, and update/publish/unpublish
 * require `expected_revision_id`. Apps hand-write nothing per tool.
 *
 * @api
 */
final readonly class ContentToolSet
{
    /**
     * @param \Closure(string, int, string): string $previewUrl Builds the app's preview
     *        URL from (entity id, expires_at, signature) — the app owns the route shape.
     */
    public function __construct(
        private ContentPublisher $publisher,
        private ContentTypeDescriptor $descriptor,
        private PreviewLinkService $previewLinks,
        private \Closure $previewUrl,
        private ?AssetStoreInterface $assets = null,
        private string $assetPrefix = 'asset',
    ) {}

    public function register(ToolRegistryInterface $registry, string $prefix): void
    {
        foreach ($this->buildTools($prefix) as $name => [$description, $schema, $handler, $redacted]) {
            $impl = new ContentOperationTool($description, $schema, $handler, $redacted);
            $registry->register(new AgentTool(
                name: $name,
                capability: $this->descriptor->publishCapability,
                destructive: true,
                dryRunSupported: false,
                category: 'content',
                inputSchema: $impl->inputSchema(),
                impl: $impl,
            ));
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: \Closure, 3: list<string>}>
     */
    private function buildTools(string $prefix): array
    {
        $p = $this->publisher;
        $noRedaction = [];

        $tools = [
            "$prefix.list" => [
                'List content items (drafts included), newest first.',
                $this->schema([
                    'published_only' => ['type' => 'boolean', 'default' => false],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ]),
                static fn(array $a, $actor): array => ['items' => $p->list(
                    $actor,
                    (bool) ($a['published_only'] ?? false),
                    (int) ($a['limit'] ?? 50),
                    (int) ($a['offset'] ?? 0),
                )],
                $noRedaction,
            ],
            "$prefix.get" => [
                'Fetch one content item by id or slug, including its revision_id concurrency token.',
                $this->schema(['id' => ['type' => 'string', 'minLength' => 1]], required: ['id']),
                static fn(array $a, $actor): array => $p->get($actor, (string) $a['id']),
                $noRedaction,
            ],
            "$prefix.createDraft" => [
                'Create an UNPUBLISHED draft. The payload is validated field-by-field; drafts are never public.',
                $this->schema([
                    'values' => $this->valuesSchema(),
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                ], required: ['values', 'idempotency_key']),
                static fn(array $a, $actor): array => $p->createDraft($actor, (array) $a['values'], (string) $a['idempotency_key']),
                $noRedaction,
            ],
            "$prefix.updateDraft" => [
                'Update fields on a draft (partial payload). Requires the current revision_id (optimistic concurrency).',
                $this->schema([
                    'id' => ['type' => 'string', 'minLength' => 1],
                    'values' => $this->valuesSchema(),
                    'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                ], required: ['id', 'values', 'expected_revision_id', 'idempotency_key']),
                static fn(array $a, $actor): array => $p->updateDraft(
                    $actor,
                    (string) $a['id'],
                    (array) $a['values'],
                    (int) $a['expected_revision_id'],
                    (string) $a['idempotency_key'],
                ),
                $noRedaction,
            ],
            "$prefix.preview" => [
                'Issue a short-lived signed preview URL rendering the item through the real site layout (noindex).',
                $this->schema([
                    'id' => ['type' => 'string', 'minLength' => 1],
                    'ttl_seconds' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400, 'default' => 1800],
                ], required: ['id']),
                fn(array $a, $actor): array => $this->previewPayload($a, $actor),
                $noRedaction,
            ],
            "$prefix.publish" => [
                'Atomically publish the item (one revision-cutting save; listings/search/sitemap react automatically).',
                $this->publicationSchema(),
                static fn(array $a, $actor): array => $p->publish(
                    $actor,
                    (string) $a['id'],
                    (int) $a['expected_revision_id'],
                    (string) $a['idempotency_key'],
                    (string) ($a['note'] ?? ''),
                ),
                $noRedaction,
            ],
            "$prefix.unpublish" => [
                'Unpublish the item. The record and its full revision history are preserved.',
                $this->publicationSchema(),
                static fn(array $a, $actor): array => $p->unpublish(
                    $actor,
                    (string) $a['id'],
                    (int) $a['expected_revision_id'],
                    (string) $a['idempotency_key'],
                    (string) ($a['note'] ?? ''),
                ),
                $noRedaction,
            ],
            "$prefix.revisions" => [
                'List the item\'s revisions, newest first, with actor, timestamp, and note.',
                $this->schema(['id' => ['type' => 'string', 'minLength' => 1]], required: ['id']),
                static fn(array $a, $actor): array => ['revisions' => $p->revisions($actor, (string) $a['id'])],
                $noRedaction,
            ],
            "$prefix.rollback" => [
                'Restore a prior revision\'s CONTENT as a new revision (history is never deleted). Publication status is deliberately unchanged: publish/unpublish remain their own decisions.',
                $this->schema([
                    'id' => ['type' => 'string', 'minLength' => 1],
                    'target_revision_id' => ['type' => 'integer', 'minimum' => 1],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                    'note' => ['type' => 'string', 'maxLength' => 500],
                ], required: ['id', 'target_revision_id', 'idempotency_key']),
                static fn(array $a, $actor): array => $p->rollback(
                    $actor,
                    (string) $a['id'],
                    (int) $a['target_revision_id'],
                    (string) $a['idempotency_key'],
                    (string) ($a['note'] ?? ''),
                ),
                $noRedaction,
            ],
        ];

        if ($this->assets !== null) {
            $assets = $this->assets;
            $tools["{$this->assetPrefix}.upload"] = [
                'Upload an image asset (base64). Validated fail-closed: content sniffing, approved types, size cap.',
                $this->schema([
                    'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'content_base64' => ['type' => 'string', 'minLength' => 4],
                ], required: ['filename', 'content_base64']),
                static function (array $a, $actor) use ($assets): array {
                    $bytes = base64_decode((string) $a['content_base64'], true);
                    if ($bytes === false) {
                        throw new AssetRejectedException(['content_base64 is not valid base64.']);
                    }

                    return $assets->upload((string) $a['filename'], $bytes, $actor);
                },
                ['content_base64'],
            ];
            $tools["{$this->assetPrefix}.get"] = [
                'Fetch a stored asset\'s URL and metadata by id.',
                $this->schema(['asset_id' => ['type' => 'string', 'minLength' => 1]], required: ['asset_id']),
                static function (array $a, $actor) use ($assets): array {
                    $asset = $assets->get((string) $a['asset_id'], $actor);

                    return $asset ?? throw new \Waaseyaa\Publishing\Exception\ContentNotFoundException((string) $a['asset_id']);
                },
                $noRedaction,
            ];
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function previewPayload(array $a, \Waaseyaa\Access\AuthorizationPrincipalInterface $actor): array
    {
        $grant = $this->publisher->preview($actor, (string) $a['id'], $this->previewLinks, (int) ($a['ttl_seconds'] ?? 1800));

        return $grant + [
            'preview_url' => ($this->previewUrl)((string) $grant['id'], $grant['expires_at'], $grant['signature']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function publicationSchema(): array
    {
        return $this->schema([
            'id' => ['type' => 'string', 'minLength' => 1],
            'expected_revision_id' => ['type' => 'integer', 'minimum' => 1],
            'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
            'note' => ['type' => 'string', 'maxLength' => 500],
        ], required: ['id', 'expected_revision_id', 'idempotency_key']);
    }

    /** @return array<string, mixed> The writable-payload schema derived from the descriptor. */
    private function valuesSchema(): array
    {
        $properties = [];
        foreach ($this->descriptor->writableFields as $field => $spec) {
            $properties[$field] = match ($spec->type) {
                'bool' => ['type' => 'boolean'],
                'int' => ['type' => 'integer'],
                default => array_filter([
                    'type' => 'string',
                    'maxLength' => $spec->maxLength,
                ], static fn($v): bool => $v !== null),
            };
            if ($spec->html) {
                $properties[$field]['description'] = 'HTML fragment; sanitized against the editorial allowlist before persistence.';
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }
}
