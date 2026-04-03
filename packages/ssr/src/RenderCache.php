<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Foundation\Http\HttpResponse;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class RenderCache
{
    public function __construct(
        private readonly CacheBackendInterface $backend,
    ) {}

    public function get(string $entityTypeId, int|string $entityId, string $viewMode, string $langcode): ?HttpResponse
    {
        $item = $this->backend->get(self::buildKey($entityTypeId, $entityId, $viewMode, $langcode));
        if ($item === false || !$item->valid || !is_array($item->data)) {
            return null;
        }

        $content = is_string($item->data['content'] ?? null) ? $item->data['content'] : '';
        $statusCode = is_int($item->data['status_code'] ?? null) ? $item->data['status_code'] : 200;
        $headers = is_array($item->data['headers'] ?? null) ? $item->data['headers'] : [];

        return new HttpResponse(
            content: $content,
            statusCode: $statusCode,
            headers: $this->normalizeHeaders($headers),
        );
    }

    public function set(
        string $entityTypeId,
        int|string $entityId,
        string $viewMode,
        string $langcode,
        HttpResponse $response,
        int $maxAge,
    ): void {
        $expire = max(0, $maxAge) > 0
            ? time() + max(0, $maxAge)
            : CacheBackendInterface::PERMANENT;

        $this->backend->set(
            self::buildKey($entityTypeId, $entityId, $viewMode, $langcode),
            [
                'content' => $response->content,
                'status_code' => $response->statusCode,
                'headers' => $response->headers,
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
        return sprintf('render:%s:%s:%s:%s', $entityTypeId, (string) $entityId, $viewMode, $langcode);
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
