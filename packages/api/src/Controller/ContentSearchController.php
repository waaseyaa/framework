<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchPage;
use Waaseyaa\Api\ContentSearch\ContentSearchQuery;
use Waaseyaa\Api\ContentSearch\ContentSearchRateLimiterInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchReadModelInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Optional, read-only public projection over the principal-safe search API.
 *
 * This controller deliberately owns a closed response shape. Search providers
 * may grow richer internal records without accidentally widening the public
 * API or disclosing raw indexed content.
 */
final class ContentSearchController
{
    private const array ALLOWED_QUERY_KEYS = [
        'q', 'page', 'page_size', 'topic', 'content_type', 'source',
        'min_quality', 'sort', 'order', 'facets',
    ];

    public function __construct(
        private readonly ContentSearchReadModelInterface $provider,
        private readonly ContentSearchRateLimiterInterface $limiter,
        private readonly int $identityMaxAttempts = 30,
        private readonly int $globalMaxAttempts = 300,
        private readonly int $windowSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function search(Request $request, AuthorizationPrincipalInterface $principal): JsonResponse
    {
        try {
            $searchRequest = $this->parseRequest($request);
        } catch (\InvalidArgumentException) {
            return self::errorResponse(400, 'Bad Request', 'The search request is malformed.');
        }

        try {
            $globalAllowed = $this->limiter->consume(
                'api-content-search:global',
                $this->globalMaxAttempts,
                $this->windowSeconds,
            );
            $identityAllowed = $globalAllowed && $this->limiter->consume(
                $this->identityBucket($principal),
                $this->identityMaxAttempts,
                $this->windowSeconds,
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('Public content search rate limiting failed.', [
                'exception_class' => $exception::class,
            ]);
            return self::errorResponse(503, 'Service Unavailable', 'Search is temporarily unavailable.');
        }

        if (!$globalAllowed || !$identityAllowed) {
            return self::errorResponse(
                429,
                'Too Many Requests',
                'The search rate limit has been exceeded.',
                ['Retry-After' => (string) $this->windowSeconds],
            );
        }

        try {
            $result = $this->provider->search($searchRequest, $principal);
        } catch (\Throwable $exception) {
            $this->logger?->error('Public content search provider failed.', [
                'exception_class' => $exception::class,
            ]);
            return self::errorResponse(503, 'Service Unavailable', 'Search is temporarily unavailable.');
        }

        $response = new JsonResponse(
            self::projectResult($result),
            200,
            self::headers(),
        );
        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }

        return $response;
    }

    private function parseRequest(Request $request): ContentSearchQuery
    {
        $query = $request->query->all();
        foreach (array_keys($query) as $key) {
            if (!in_array($key, self::ALLOWED_QUERY_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown search query parameter.');
            }
        }

        $searchTerm = $query['q'] ?? null;
        if (!is_string($searchTerm) || trim($searchTerm) === '') {
            throw new \InvalidArgumentException('Search query is required.');
        }

        return new ContentSearchQuery(
            query: trim($searchTerm),
            topics: self::stringList($query['topic'] ?? []),
            contentType: self::stringValue($query['content_type'] ?? ''),
            sourceNames: self::stringList($query['source'] ?? []),
            minQuality: self::integerValue($query['min_quality'] ?? 0, 0, 100),
            sortField: self::stringValue($query['sort'] ?? 'relevance'),
            sortOrder: self::stringValue($query['order'] ?? 'desc'),
            page: self::integerValue($query['page'] ?? 1, 1, 200),
            pageSize: self::integerValue($query['page_size'] ?? 20, 1, 100),
            includeFacets: self::booleanValue($query['facets'] ?? true),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Expected a string list.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Expected a string list.');
            }
        }

        return $value;
    }

    private static function stringValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string.');
        }

        return $value;
    }

    private static function integerValue(mixed $value, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($parsed)) {
                throw new \InvalidArgumentException('Integer is outside the supported range.');
            }
        } else {
            throw new \InvalidArgumentException('Expected an integer.');
        }
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Integer is outside the supported range.');
        }

        return $parsed;
    }

    private static function booleanValue(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw new \InvalidArgumentException('Expected a boolean.'),
        };
    }

    private function identityBucket(AuthorizationPrincipalInterface $principal): string
    {
        if (!$principal->isAuthenticated()) {
            return 'api-content-search:anonymous';
        }

        return 'api-content-search:principal:' . hash('sha256', json_encode([
            (string) $principal->id(),
            $principal->tenantId(),
            $principal->communityId(),
            $principal->claimsGeneration(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function projectResult(ContentSearchPage $result): array
    {
        return [
            'jsonapi' => ['version' => '1.1'],
            'data' => array_map(static fn(array $hit): array => [
                'type' => 'content-search-result',
                'id' => $hit['id'],
                'attributes' => array_diff_key($hit, ['id' => true]),
            ], $result->safeHits()),
            'meta' => [
                'totalHits' => $result->totalHits,
                'totalPages' => $result->totalPages,
                'currentPage' => $result->currentPage,
                'pageSize' => $result->pageSize,
                'isComplete' => $result->isComplete,
                'facets' => $result->safeFacets(),
            ],
        ];
    }

    /** @param array<string, string> $extraHeaders */
    private static function errorResponse(int $status, string $title, string $detail, array $extraHeaders = []): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status, [...self::headers(), ...$extraHeaders]);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'Content-Type' => 'application/vnd.api+json',
            'Cache-Control' => 'no-store',
        ];
    }
}
