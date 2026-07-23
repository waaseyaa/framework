<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class RenderCache
{
    /**
     * Cache-key schema version. Bump this whenever the SHAPE of the rendered
     * payload changes in a way that makes previously-cached entries unsafe to
     * keep serving — e.g. the R6 PR1 fix that made anonymous HTML rendering
     * field-access-aware, the R6 PR2 fix that made the SSR node render gate
     * entity-view-access-aware, the R7 WP1 fix that made the HTML `<title>`
     * and schema.org JSON-LD `name` field-access-aware for the entity label,
     * or the R8-a fix that generalized the entity-level view gate
     * ({@see \Waaseyaa\SSR\SsrPageHandler::shouldDenyEntityRender()}) from the
     * `content` group to every entity type reachable via SSR render
     * (see CHANGELOG "Security"), or the #2117 application page-composition
     * contract that changes which full HTML document owns the entity body.
     * Folding this into {@see buildKey()} makes
     * every pre-fix cache entry unreachable under the new key, so already-
     * cached leaky pages — including any non-content-group entity that leaked
     * whole-entity to anonymous before the R8-a fix — are re-rendered through
     * the fixed path instead of continuing to serve stale, unfiltered HTML.
     */
    public const string SCHEMA_VERSION = 'v6';

    public function __construct(
        private readonly CacheBackendInterface $backend,
    ) {}

    public function get(string $entityTypeId, int|string $entityId, string $viewMode, string $langcode): ?Response
    {
        $item = $this->backend->get(self::buildKey($entityTypeId, $entityId, $viewMode, $langcode));
        if ($item === false || !$item->valid || !is_array($item->data)) {
            return null;
        }

        $content = is_string($item->data['content'] ?? null) ? $item->data['content'] : '';
        $statusCode = is_int($item->data['status_code'] ?? null) ? $item->data['status_code'] : 200;
        $headers = is_array($item->data['headers'] ?? null) ? $item->data['headers'] : [];

        $response = new Response($content, $statusCode);
        foreach ($this->normalizeHeaders($headers) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public function set(
        string $entityTypeId,
        int|string $entityId,
        string $viewMode,
        string $langcode,
        Response $response,
        int $maxAge,
    ): void {
        $expire = max(0, $maxAge) > 0
            ? time() + max(0, $maxAge)
            : CacheBackendInterface::PERMANENT;

        /** @var array<string, string> $headerArray */
        $headerArray = [];
        foreach ($response->headers->all() as $name => $values) {
            if (is_array($values) && $values !== []) {
                $headerArray[$name] = $values[0];
            }
        }

        $this->backend->set(
            self::buildKey($entityTypeId, $entityId, $viewMode, $langcode),
            [
                'content' => $response->getContent(),
                'status_code' => $response->getStatusCode(),
                'headers' => $headerArray,
            ],
            $expire,
            self::buildTags($entityTypeId, $entityId),
        );
    }

    public function invalidateEntity(string $entityTypeId, int|string|null $entityId): void
    {
        if (!$this->backend instanceof TagAwareCacheInterface) {
            return;
        }

        $tags = $entityId !== null && $entityId !== ''
            ? ["render:entity:{$entityTypeId}:{$entityId}"]
            : ["render:entity:{$entityTypeId}"];

        $this->backend->invalidateByTags($tags);
    }

    public static function buildKey(string $entityTypeId, int|string $entityId, string $viewMode, string $langcode): string
    {
        return sprintf('render:%s:%s:%s:%s:%s', self::SCHEMA_VERSION, $entityTypeId, (string) $entityId, $viewMode, $langcode);
    }

    /**
     * @return list<string>
     */
    private static function buildTags(string $entityTypeId, int|string $entityId): array
    {
        return [
            'render',
            "render:entity:{$entityTypeId}",
            "render:entity:{$entityTypeId}:{$entityId}",
            "entity:{$entityTypeId}",
            "entity:{$entityTypeId}:{$entityId}",
        ];
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }
}
