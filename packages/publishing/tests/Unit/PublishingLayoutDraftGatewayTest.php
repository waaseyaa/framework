<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentRevisionPreviewInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;
use Waaseyaa\Publishing\PageBuilder\PublishingRevisionPreviewGateway;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewUrlGeneratorInterface;

final class PublishingLayoutDraftGatewayTest extends TestCase
{
    #[Test]
    public function projectsOnlyTheConfiguredLayoutFieldAndForwardsEveryWriteGuard(): void
    {
        $publisher = new RecordingContentDraftMutation();
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout');
        $actor = new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');

        $read = $gateway->read($actor, '42');
        self::assertSame(7, $read->entityRevisionId);
        self::assertSame('{"schema":"waaseyaa.layout"}', $read->encodedLayout);

        $updated = $gateway->update($actor, '42', '{"schema":"waaseyaa.layout","version":1}', 7, 'edit-42');
        self::assertSame(8, $updated->entityRevisionId);
        self::assertSame(['page_layout' => '{"schema":"waaseyaa.layout","version":1}'], $publisher->values);
        self::assertSame(7, $publisher->expectedRevisionId);
        self::assertSame('edit-42', $publisher->idempotencyKey);
    }

    #[Test]
    public function revisionPreviewAdapterForwardsTheExactRevisionAndReturnsTheGrant(): void
    {
        $publisher = new RecordingRevisionPreview();
        $gateway = new PublishingRevisionPreviewGateway(
            $publisher,
            new PreviewLinkService('preview-secret', fn(): int => 1_000_000),
            new TestRevisionPreviewUrlGenerator(),
            600,
        );
        $actor = new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');

        $grant = $gateway->issue($actor, '42', 9);

        self::assertSame('42', $grant->entityId);
        self::assertSame(9, $grant->revisionId);
        self::assertSame(600, $publisher->ttlSeconds);
        self::assertSame(9, $publisher->expectedRevisionId);
        self::assertSame('/preview/42?revision=9&expires=1000600&signature=signed', $grant->previewUrl);
    }

    #[Test]
    public function revisionPreviewAdapterRejectsAnInvalidTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublishingRevisionPreviewGateway(
            new RecordingRevisionPreview(),
            new PreviewLinkService('preview-secret'),
            new TestRevisionPreviewUrlGenerator(),
            0,
        );
    }

    #[Test]
    public function revisionPreviewAdapterNormalizesAuthorizationFailure(): void
    {
        $this->expectException(PageBuilderAccessDeniedException::class);
        $this->failingPreviewGateway(new ContentAuthorizationException())->issue($this->actor(), '42', 9);
    }

    #[Test]
    public function revisionPreviewAdapterNormalizesMissingContent(): void
    {
        $this->expectException(PageBuilderDraftNotFoundException::class);
        $this->failingPreviewGateway(new ContentNotFoundException('42'))->issue($this->actor(), '42', 9);
    }

    #[Test]
    public function revisionPreviewAdapterPreservesConflictMetadata(): void
    {
        $gateway = $this->failingPreviewGateway(new RevisionConflictException('node', '42', 9, 10));
        try {
            $gateway->issue($this->actor(), '42', 9);
            self::fail('A stale revision was accepted.');
        } catch (StaleEntityRevisionException $exception) {
            self::assertSame(9, $exception->expectedRevisionId);
            self::assertSame(10, $exception->currentRevisionId);
        }
    }

    private function actor(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }

    private function failingPreviewGateway(\Throwable $failure): PublishingRevisionPreviewGateway
    {
        return new PublishingRevisionPreviewGateway(
            new FailingRevisionPreview($failure),
            new PreviewLinkService('preview-secret'),
            new TestRevisionPreviewUrlGenerator(),
        );
    }
}

final class TestRevisionPreviewUrlGenerator implements RevisionPreviewUrlGeneratorInterface
{
    public function generate(string $entityId, int $revisionId, int $expiresAt, string $signature): string
    {
        return sprintf(
            '/preview/%s?revision=%d&expires=%d&signature=%s',
            rawurlencode($entityId),
            $revisionId,
            $expiresAt,
            rawurlencode($signature),
        );
    }
}

final class RecordingRevisionPreview implements ContentRevisionPreviewInterface
{
    public ?int $ttlSeconds = null;
    public ?int $expectedRevisionId = null;

    public function previewRevision(
        AuthorizationPrincipalInterface $actor,
        string $idOrSlug,
        int $expectedRevisionId,
        PreviewLinkService $links,
        int $ttlSeconds = 1800,
    ): array {
        $this->ttlSeconds = $ttlSeconds;
        $this->expectedRevisionId = $expectedRevisionId;

        return [
            'id' => $idOrSlug,
            'entity_type' => 'node',
            'revision_id' => $expectedRevisionId,
            'expires_at' => 1_000_600,
            'signature' => 'signed',
        ];
    }
}

final readonly class FailingRevisionPreview implements ContentRevisionPreviewInterface
{
    public function __construct(private \Throwable $failure) {}

    public function previewRevision(
        AuthorizationPrincipalInterface $actor,
        string $idOrSlug,
        int $expectedRevisionId,
        PreviewLinkService $links,
        int $ttlSeconds = 1800,
    ): array {
        throw $this->failure;
    }
}

final class RecordingContentDraftMutation implements ContentDraftMutationInterface
{
    /** @var array<string, mixed> */
    public array $values = [];
    public ?int $expectedRevisionId = null;
    public ?string $idempotencyKey = null;

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return [
            'id' => '42',
            'revision_id' => 7,
            'page_layout' => '{"schema":"waaseyaa.layout"}',
            'body' => 'must not become layout authority',
        ];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array {
        $this->values = $values;
        $this->expectedRevisionId = $expectedRevisionId;
        $this->idempotencyKey = $idempotencyKey;

        return [
            'id' => $id,
            'revision_id' => 8,
            'page_layout' => $values['page_layout'],
        ];
    }
}
