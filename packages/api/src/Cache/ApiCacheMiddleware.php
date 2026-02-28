<?php

declare(strict_types=1);

namespace Aurora\Api\Cache;

use Aurora\Api\JsonApiDocument;

/**
 * HTTP cache middleware for API responses.
 *
 * Handles:
 * - ETag generation from response body hash
 * - If-None-Match conditional request handling (304 Not Modified)
 * - Cache-Control header generation for different response types
 * - Vary header for content negotiation
 *
 * This is a plain PHP middleware that operates on JsonApiDocument objects
 * and request headers, returning header arrays. It is not tied to any
 * HTTP framework.
 */
final class ApiCacheMiddleware
{
    /**
     * Default max-age for different response types (in seconds).
     */
    private const DEFAULT_MAX_AGE = [
        'entity' => 0,
        'collection' => 0,
        'schema' => 3600,
    ];

    /**
     * @param int|null $entityMaxAge     Max-age for single entity responses (seconds).
     * @param int|null $collectionMaxAge Max-age for collection responses (seconds).
     * @param int|null $schemaMaxAge     Max-age for schema responses (seconds).
     * @param bool     $isPrivate        Whether responses should be marked as private (no CDN caching).
     */
    public function __construct(
        private readonly ?int $entityMaxAge = null,
        private readonly ?int $collectionMaxAge = null,
        private readonly ?int $schemaMaxAge = null,
        private readonly bool $isPrivate = true,
    ) {}

    /**
     * Generate an ETag for a JSON:API document.
     *
     * The ETag is a weak validator based on the SHA-256 hash of the serialized
     * response body. Weak ETags (W/"...") are appropriate because the same
     * logical content can have different byte representations.
     */
    public function generateETag(JsonApiDocument $document): string
    {
        $body = json_encode($document->toArray(), \JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $body);

        return 'W/"' . substr($hash, 0, 32) . '"';
    }

    /**
     * Check if the request's If-None-Match header matches the response ETag.
     *
     * @param string $ifNoneMatch The value of the If-None-Match request header.
     * @param string $etag        The current ETag for the response.
     *
     * @return bool True if the client's cache is still valid (304 should be returned).
     */
    public function isNotModified(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '' || $etag === '') {
            return false;
        }

        // If-None-Match can contain multiple ETags: W/"abc", W/"def"
        $clientETags = array_map('trim', explode(',', $ifNoneMatch));

        // Wildcard match.
        if (in_array('*', $clientETags, true)) {
            return true;
        }

        // Compare ETags (weak comparison per RFC 7232).
        foreach ($clientETags as $clientETag) {
            if ($this->weakCompare($clientETag, $etag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build cache-related HTTP headers for a JSON:API response.
     *
     * @param JsonApiDocument $document     The response document.
     * @param string          $responseType The type of response: 'entity', 'collection', or 'schema'.
     *
     * @return array<string, string> Headers to add to the HTTP response.
     */
    public function buildHeaders(JsonApiDocument $document, string $responseType = 'entity'): array
    {
        $headers = [];

        // Generate ETag.
        $etag = $this->generateETag($document);
        $headers['ETag'] = $etag;

        // Cache-Control.
        $maxAge = $this->getMaxAge($responseType);
        $visibility = $this->isPrivate ? 'private' : 'public';

        if ($maxAge > 0) {
            $headers['Cache-Control'] = "{$visibility}, max-age={$maxAge}, must-revalidate";
        } else {
            $headers['Cache-Control'] = "{$visibility}, no-cache, must-revalidate";
        }

        // Vary header for content negotiation.
        $headers['Vary'] = 'Accept, Accept-Language, Authorization';

        return $headers;
    }

    /**
     * Process a request/response pair and return cache headers.
     *
     * If the request includes If-None-Match that matches the response ETag,
     * returns a 304 status indicator. Otherwise returns full cache headers.
     *
     * @param JsonApiDocument $document     The response document.
     * @param string          $responseType The type: 'entity', 'collection', or 'schema'.
     * @param string          $ifNoneMatch  The If-None-Match request header value.
     *
     * @return array{headers: array<string, string>, notModified: bool}
     */
    public function process(JsonApiDocument $document, string $responseType = 'entity', string $ifNoneMatch = ''): array
    {
        $headers = $this->buildHeaders($document, $responseType);

        $notModified = false;
        if ($ifNoneMatch !== '') {
            $notModified = $this->isNotModified($ifNoneMatch, $headers['ETag']);
        }

        return [
            'headers' => $headers,
            'notModified' => $notModified,
        ];
    }

    /**
     * Get the max-age for a given response type.
     */
    private function getMaxAge(string $responseType): int
    {
        return match ($responseType) {
            'entity' => $this->entityMaxAge ?? self::DEFAULT_MAX_AGE['entity'],
            'collection' => $this->collectionMaxAge ?? self::DEFAULT_MAX_AGE['collection'],
            'schema' => $this->schemaMaxAge ?? self::DEFAULT_MAX_AGE['schema'],
            default => 0,
        };
    }

    /**
     * Perform weak ETag comparison per RFC 7232 Section 2.3.2.
     *
     * Weak comparison: strip W/ prefix from both, compare the opaque-tag values.
     */
    private function weakCompare(string $etag1, string $etag2): bool
    {
        $normalize = static function (string $etag): string {
            // Strip W/ prefix for weak comparison.
            if (str_starts_with($etag, 'W/')) {
                return substr($etag, 2);
            }

            return $etag;
        };

        return $normalize($etag1) === $normalize($etag2);
    }
}
