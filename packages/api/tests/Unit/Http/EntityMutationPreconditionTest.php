<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Http\EntityMutationPrecondition;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;

#[CoversClass(EntityMutationPrecondition::class)]
final class EntityMutationPreconditionTest extends TestCase
{
    #[Test]
    public function missingOrBlankIfMatchIs428WithoutConsultingGetEtags(): void
    {
        foreach ([null, '', '   '] as $header) {
            $document = EntityMutationPrecondition::fromIfMatch($header);
            self::assertInstanceOf(JsonApiDocument::class, $document);
            self::assertSame(428, $document->statusCode);
            self::assertCanonicalEnvelope($document, 428, EntityMutationPrecondition::REQUIRED_CODE);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedIfMatchValues(): iterable
    {
        yield 'weak' => ['W/"emt1.invalid"'];
        yield 'wildcard' => ['*'];
        yield 'quoted-wildcard' => ['"*"'];
        yield 'comma-list' => ['"one", "two"'];
        yield 'unquoted' => ['emt1.not-an-etag'];
        yield 'empty-quotes' => ['""'];
        yield 'malformed-opaque' => ['"emt1.%%%%"'];
    }

    #[Test]
    #[DataProvider('malformedIfMatchValues')]
    public function malformedWeakListAndWildcardAre400(string $ifMatch): void
    {
        $document = EntityMutationPrecondition::fromIfMatch($ifMatch);
        self::assertInstanceOf(JsonApiDocument::class, $document);
        self::assertCanonicalEnvelope($document, 400, EntityMutationPrecondition::INVALID_CODE);
        self::assertStringNotContainsString('emt1.', json_encode($document->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function validStrongEtagReturnsThePolicyToken(): void
    {
        $token = EntityMutationToken::issue('primary', 'default', 'node', '42', 1);
        $parsed = EntityMutationPrecondition::fromIfMatch($token->toStrongEtag());
        self::assertInstanceOf(EntityMutationToken::class, $parsed);
        self::assertSame($token->toOpaqueString(), $parsed->toOpaqueString());
    }

    #[Test]
    public function fromRequestReadsIfMatchAndNeverUsesGetEtags(): void
    {
        $request = Request::create('/api/node/1', 'PATCH');
        $request->headers->set('If-Match', 'W/"weak", "also-listed"');

        $document = EntityMutationPrecondition::fromRequest($request);

        self::assertInstanceOf(JsonApiDocument::class, $document);
        self::assertCanonicalEnvelope($document, 400, EntityMutationPrecondition::INVALID_CODE);
        self::assertNotSame(
            $request->getETags(),
            [trim((string) $request->headers->get('If-Match'))],
            'Request::getETags() must not be the parse path; it accepts weak/list validators.',
        );
    }

    #[Test]
    public function failedEnvelopeNeverIncludesAWinningToken(): void
    {
        $winner = EntityMutationToken::issue('primary', 'default', 'node', '42', 2);
        $encoded = json_encode(EntityMutationPrecondition::failedDocument()->toArray(), JSON_THROW_ON_ERROR);

        self::assertCanonicalEnvelope(EntityMutationPrecondition::failedDocument(), 412, EntityMutationPrecondition::FAILED_CODE);
        self::assertStringNotContainsString($winner->toOpaqueString(), $encoded);
        self::assertStringNotContainsString('emt1.', $encoded);
        self::assertStringNotContainsString('ETag', $encoded);
        self::assertArrayNotHasKey('meta', EntityMutationPrecondition::failedDocument()->toArray()['errors'][0]);
    }

    #[Test]
    public function responseUsesTheDocumentStatusAndJsonApiContentType(): void
    {
        $response = EntityMutationPrecondition::response(EntityMutationPrecondition::requiredDocument());

        self::assertSame(428, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(EntityMutationPrecondition::REQUIRED_CODE, $body['errors'][0]['code'] ?? null);
    }

    private static function assertCanonicalEnvelope(JsonApiDocument $document, int $status, string $code): void
    {
        $payload = $document->toArray();
        self::assertSame($status, $document->statusCode);
        self::assertSame('1.1', $payload['jsonapi']['version'] ?? null);
        self::assertArrayHasKey('errors', $payload);
        self::assertArrayNotHasKey('data', $payload);
        $error = $payload['errors'][0];
        self::assertSame((string) $status, $error['status'] ?? null);
        self::assertSame($code, $error['code'] ?? null);
        self::assertArrayHasKey('title', $error);
        self::assertArrayHasKey('detail', $error);
        self::assertArrayNotHasKey('meta', $error);
        self::assertSame(['status', 'title', 'code', 'detail'], array_keys($error));
    }
}
