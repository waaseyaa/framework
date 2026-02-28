<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Cache;

use Aurora\Api\Cache\ApiCacheMiddleware;
use Aurora\Api\JsonApiDocument;
use Aurora\Api\JsonApiResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiCacheMiddleware::class)]
final class ApiCacheMiddlewareTest extends TestCase
{
    private ApiCacheMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ApiCacheMiddleware();
    }

    // --- ETag Generation ---

    #[Test]
    public function generateETagReturnsWeakETag(): void
    {
        $document = $this->createEntityDocument();

        $etag = $this->middleware->generateETag($document);

        $this->assertStringStartsWith('W/"', $etag);
        $this->assertStringEndsWith('"', $etag);
    }

    #[Test]
    public function generateETagIsDeterministic(): void
    {
        $document = $this->createEntityDocument();

        $etag1 = $this->middleware->generateETag($document);
        $etag2 = $this->middleware->generateETag($document);

        $this->assertSame($etag1, $etag2);
    }

    #[Test]
    public function generateETagDiffersForDifferentContent(): void
    {
        $doc1 = $this->createEntityDocument();
        $doc2 = $this->createEntityDocument(title: 'Different Title');

        $etag1 = $this->middleware->generateETag($doc1);
        $etag2 = $this->middleware->generateETag($doc2);

        $this->assertNotSame($etag1, $etag2);
    }

    // --- If-None-Match / 304 ---

    #[Test]
    public function isNotModifiedReturnsTrueForMatchingETag(): void
    {
        $document = $this->createEntityDocument();
        $etag = $this->middleware->generateETag($document);

        $this->assertTrue($this->middleware->isNotModified($etag, $etag));
    }

    #[Test]
    public function isNotModifiedReturnsFalseForNonMatchingETag(): void
    {
        $this->assertFalse($this->middleware->isNotModified('W/"abc123"', 'W/"def456"'));
    }

    #[Test]
    public function isNotModifiedHandlesMultipleETags(): void
    {
        $document = $this->createEntityDocument();
        $etag = $this->middleware->generateETag($document);

        $ifNoneMatch = 'W/"old1", ' . $etag . ', W/"old2"';

        $this->assertTrue($this->middleware->isNotModified($ifNoneMatch, $etag));
    }

    #[Test]
    public function isNotModifiedHandlesWildcard(): void
    {
        $this->assertTrue($this->middleware->isNotModified('*', 'W/"anything"'));
    }

    #[Test]
    public function isNotModifiedReturnsFalseForEmptyHeaders(): void
    {
        $this->assertFalse($this->middleware->isNotModified('', 'W/"abc"'));
        $this->assertFalse($this->middleware->isNotModified('W/"abc"', ''));
    }

    #[Test]
    public function isNotModifiedWeakComparison(): void
    {
        // Weak comparison should match W/"x" with "x" (stripped W/ prefix).
        $this->assertTrue($this->middleware->isNotModified('"abc123"', 'W/"abc123"'));
        $this->assertTrue($this->middleware->isNotModified('W/"abc123"', '"abc123"'));
    }

    // --- Cache-Control Headers ---

    #[Test]
    public function buildHeadersForEntityResponse(): void
    {
        $document = $this->createEntityDocument();

        $headers = $this->middleware->buildHeaders($document, 'entity');

        $this->assertArrayHasKey('ETag', $headers);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertArrayHasKey('Vary', $headers);

        // Entity responses default to no-cache.
        $this->assertStringContainsString('private', $headers['Cache-Control']);
        $this->assertStringContainsString('no-cache', $headers['Cache-Control']);
        $this->assertStringContainsString('must-revalidate', $headers['Cache-Control']);
    }

    #[Test]
    public function buildHeadersForCollectionResponse(): void
    {
        $document = $this->createCollectionDocument();

        $headers = $this->middleware->buildHeaders($document, 'collection');

        $this->assertStringContainsString('no-cache', $headers['Cache-Control']);
    }

    #[Test]
    public function buildHeadersForSchemaResponse(): void
    {
        $document = $this->createSchemaDocument();

        $headers = $this->middleware->buildHeaders($document, 'schema');

        // Schema responses default to 1 hour max-age.
        $this->assertStringContainsString('max-age=3600', $headers['Cache-Control']);
        $this->assertStringContainsString('must-revalidate', $headers['Cache-Control']);
    }

    #[Test]
    public function buildHeadersIncludesVaryHeader(): void
    {
        $document = $this->createEntityDocument();

        $headers = $this->middleware->buildHeaders($document, 'entity');

        $this->assertSame('Accept, Accept-Language, Authorization', $headers['Vary']);
    }

    #[Test]
    public function buildHeadersWithCustomMaxAge(): void
    {
        $middleware = new ApiCacheMiddleware(
            entityMaxAge: 300,
            collectionMaxAge: 60,
            schemaMaxAge: 7200,
        );

        $document = $this->createEntityDocument();

        $entityHeaders = $middleware->buildHeaders($document, 'entity');
        $this->assertStringContainsString('max-age=300', $entityHeaders['Cache-Control']);

        $collectionHeaders = $middleware->buildHeaders($this->createCollectionDocument(), 'collection');
        $this->assertStringContainsString('max-age=60', $collectionHeaders['Cache-Control']);

        $schemaHeaders = $middleware->buildHeaders($this->createSchemaDocument(), 'schema');
        $this->assertStringContainsString('max-age=7200', $schemaHeaders['Cache-Control']);
    }

    #[Test]
    public function buildHeadersPublicWhenNotPrivate(): void
    {
        $middleware = new ApiCacheMiddleware(
            schemaMaxAge: 3600,
            isPrivate: false,
        );

        $document = $this->createSchemaDocument();
        $headers = $middleware->buildHeaders($document, 'schema');

        $this->assertStringContainsString('public', $headers['Cache-Control']);
        $this->assertStringNotContainsString('private', $headers['Cache-Control']);
    }

    // --- Process (combined request/response) ---

    #[Test]
    public function processReturnsHeadersAndNotModifiedFalse(): void
    {
        $document = $this->createEntityDocument();

        $result = $this->middleware->process($document, 'entity');

        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('notModified', $result);
        $this->assertFalse($result['notModified']);
        $this->assertArrayHasKey('ETag', $result['headers']);
    }

    #[Test]
    public function processDetects304NotModified(): void
    {
        $document = $this->createEntityDocument();
        $etag = $this->middleware->generateETag($document);

        $result = $this->middleware->process($document, 'entity', $etag);

        $this->assertTrue($result['notModified']);
    }

    #[Test]
    public function processReturnsNotModifiedFalseForStaleETag(): void
    {
        $document = $this->createEntityDocument();

        $result = $this->middleware->process($document, 'entity', 'W/"stale-etag"');

        $this->assertFalse($result['notModified']);
    }

    #[Test]
    public function processWithEmptyIfNoneMatch(): void
    {
        $document = $this->createEntityDocument();

        $result = $this->middleware->process($document, 'entity', '');

        $this->assertFalse($result['notModified']);
    }

    // --- Helpers ---

    private function createEntityDocument(string $title = 'Test Article'): JsonApiDocument
    {
        $resource = new JsonApiResource(
            type: 'article',
            id: '550e8400-e29b-41d4-a716-446655440000',
            attributes: ['title' => $title, 'body' => 'Test content.'],
            links: ['self' => '/api/article/550e8400-e29b-41d4-a716-446655440000'],
        );

        return JsonApiDocument::fromResource(
            $resource,
            links: ['self' => '/api/article/550e8400-e29b-41d4-a716-446655440000'],
        );
    }

    private function createCollectionDocument(): JsonApiDocument
    {
        return JsonApiDocument::fromCollection(
            [
                new JsonApiResource(type: 'article', id: '1', attributes: ['title' => 'First']),
                new JsonApiResource(type: 'article', id: '2', attributes: ['title' => 'Second']),
            ],
            links: ['self' => '/api/article'],
            meta: ['total' => 2],
        );
    }

    private function createSchemaDocument(): JsonApiDocument
    {
        return new JsonApiDocument(
            meta: [
                'schema' => [
                    'title' => 'Article',
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            links: ['self' => '/api/schema/article'],
        );
    }
}
