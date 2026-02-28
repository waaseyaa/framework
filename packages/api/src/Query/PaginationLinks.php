<?php

declare(strict_types=1);

namespace Aurora\Api\Query;

/**
 * Generates JSON:API pagination link URLs.
 */
final class PaginationLinks
{
    /**
     * Generate pagination link URLs.
     *
     * @param string $basePath The base path for the collection endpoint (e.g., "/api/article").
     * @param int    $offset   Current offset.
     * @param int    $limit    Current page size.
     * @param int    $total    Total number of matching entities.
     *
     * @return array{self: string, first: string, next?: string, prev?: string}
     */
    public static function generate(
        string $basePath,
        int $offset,
        int $limit,
        int $total,
    ): array {
        $links = [
            'self' => self::buildUrl($basePath, $offset, $limit),
            'first' => self::buildUrl($basePath, 0, $limit),
        ];

        // Add "prev" link if we're not on the first page.
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $links['prev'] = self::buildUrl($basePath, $prevOffset, $limit);
        }

        // Add "next" link if there are more results.
        $nextOffset = $offset + $limit;
        if ($nextOffset < $total) {
            $links['next'] = self::buildUrl($basePath, $nextOffset, $limit);
        }

        return $links;
    }

    /**
     * Build a paginated URL.
     */
    private static function buildUrl(string $basePath, int $offset, int $limit): string
    {
        return sprintf('%s?page[offset]=%d&page[limit]=%d', $basePath, $offset, $limit);
    }
}
