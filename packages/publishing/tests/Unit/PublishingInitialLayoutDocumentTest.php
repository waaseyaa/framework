<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Draft\InitialLayoutDocumentProviderInterface;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentRevisionHistoryInterface;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;
use Waaseyaa\Publishing\PageBuilder\PublishingPageBuilderRevisionGateway;

/**
 * The initial-layout-document seam (#2556).
 *
 * A consumer whose content was migrated from another CMS has entities with no
 * stored page-builder document. LayoutDraftSnapshot cannot legally hold that
 * state, so before this seam both publishing gateways could only refuse such
 * an entity and the consumer had to fork them. A gateway composed with an
 * InitialLayoutDocumentProviderInterface projects the application's document
 * instead, without a write; a gateway composed without one keeps the exact
 * refusal behaviour, pinned here.
 */
final class PublishingInitialLayoutDocumentTest extends TestCase
{
    private const string INITIAL_DOCUMENT = '{"schema":"waaseyaa.layout","sections":[],"template":{"id":"standard","version":1},"version":1}';

    /** @return iterable<string, array{null|string}> */
    public static function absentDocumentRepresentations(): iterable
    {
        yield 'null' => [null];
        yield 'absent' => ['ABSENT'];
        yield 'empty string' => [''];
        yield 'whitespace only' => [" \t\n"];
    }

    #[Test]
    #[DataProvider('absentDocumentRepresentations')]
    public function draftReadProjectsTheInitialDocumentWithoutAWrite(?string $stored): void
    {
        $values = ['id' => 42, 'revision_id' => 17];
        if ('ABSENT' !== $stored) {
            $values['page_layout'] = $stored;
        }
        $publisher = new InitialDocumentRecordingMutation($values);
        $provider = new RecordingInitialLayoutDocuments(self::INITIAL_DOCUMENT);
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout', $provider);

        $snapshot = $gateway->read($this->actor(), '42');

        self::assertSame('42', $snapshot->entityId);
        self::assertSame(17, $snapshot->entityRevisionId);
        self::assertSame(self::INITIAL_DOCUMENT, $snapshot->encodedLayout);
        self::assertSame(['42'], $provider->requestedEntityIds);
        self::assertSame([], $publisher->writes, 'Projecting the initial document must never mutate the entity.');
    }

    #[Test]
    public function draftReadPrefersTheStoredDocumentOverTheProvider(): void
    {
        $publisher = new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17, 'page_layout' => '{"schema":"waaseyaa.layout"}']);
        $provider = new RecordingInitialLayoutDocuments(self::INITIAL_DOCUMENT);
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout', $provider);

        $snapshot = $gateway->read($this->actor(), '42');

        self::assertSame('{"schema":"waaseyaa.layout"}', $snapshot->encodedLayout);
        self::assertSame([], $provider->requestedEntityIds, 'A stored document must not consult the provider.');
    }

    #[Test]
    public function draftUpdateStillWritesTheSubmittedDocument(): void
    {
        $publisher = new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17]);
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout', new RecordingInitialLayoutDocuments(self::INITIAL_DOCUMENT));

        $updated = $gateway->update($this->actor(), '42', '{"schema":"waaseyaa.layout","version":1}', 17, 'edit-42');

        self::assertSame(18, $updated->entityRevisionId);
        self::assertSame('{"schema":"waaseyaa.layout","version":1}', $updated->encodedLayout);
        self::assertSame([['page_layout' => '{"schema":"waaseyaa.layout","version":1}']], array_column($publisher->writes, 'values'));
    }

    #[Test]
    public function draftGatewayWithoutAProviderStillRefusesAMissingDocument(): void
    {
        $gateway = new PublishingLayoutDraftGateway(
            new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17, 'page_layout' => null]),
            'page_layout',
        );

        $this->expectException(\UnexpectedValueException::class);

        $gateway->read($this->actor(), '42');
    }

    #[Test]
    public function draftGatewayWithoutAProviderStillRefusesAnEmptyDocument(): void
    {
        $gateway = new PublishingLayoutDraftGateway(
            new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17, 'page_layout' => '']),
            'page_layout',
        );

        $this->expectException(\InvalidArgumentException::class);

        $gateway->read($this->actor(), '42');
    }

    #[Test]
    public function aCorruptStoredDocumentIsRefusedEvenWithAProvider(): void
    {
        $gateway = new PublishingLayoutDraftGateway(
            new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17, 'page_layout' => 7]),
            'page_layout',
            new RecordingInitialLayoutDocuments(self::INITIAL_DOCUMENT),
        );

        $this->expectException(\UnexpectedValueException::class);

        $gateway->read($this->actor(), '42');
    }

    #[Test]
    public function anEmptyProviderDocumentIsRefused(): void
    {
        $gateway = new PublishingLayoutDraftGateway(
            new InitialDocumentRecordingMutation(['id' => 42, 'revision_id' => 17]),
            'page_layout',
            new RecordingInitialLayoutDocuments(" \n"),
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('initial layout document');

        $gateway->read($this->actor(), '42');
    }

    #[Test]
    public function historyProjectsTheInitialDocumentForRevisionsWithoutOne(): void
    {
        $publisher = new InitialDocumentRevisionHistory();
        $gateway = new PublishingPageBuilderRevisionGateway($publisher, 'page_layout', new RecordingInitialLayoutDocuments(self::INITIAL_DOCUMENT));

        $history = $gateway->list($this->actor(), '42');
        $revision = $gateway->readRevision($this->actor(), '42', 8);
        $restored = $gateway->restore($this->actor(), '42', 8, 9, 'restore-1');

        self::assertSame(self::INITIAL_DOCUMENT, $history[0]->encodedLayout);
        self::assertSame('{"schema":"waaseyaa.layout"}', $history[1]->encodedLayout, 'A stored revision document must survive unchanged.');
        self::assertSame(self::INITIAL_DOCUMENT, $revision->encodedLayout);
        self::assertSame(self::INITIAL_DOCUMENT, $restored->encodedLayout);
    }

    #[Test]
    public function historyWithoutAProviderStillRefusesARevisionWithoutADocument(): void
    {
        $gateway = new PublishingPageBuilderRevisionGateway(new InitialDocumentRevisionHistory(), 'page_layout');

        $this->expectException(\UnexpectedValueException::class);

        $gateway->readRevision($this->actor(), '42', 8);
    }

    private function actor(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }
}

final class RecordingInitialLayoutDocuments implements InitialLayoutDocumentProviderInterface
{
    /** @var list<string> */
    public array $requestedEntityIds = [];

    public function __construct(private readonly string $document) {}

    public function initialEncodedLayout(string $entityId): string
    {
        $this->requestedEntityIds[] = $entityId;

        return $this->document;
    }
}

final class InitialDocumentRecordingMutation implements ContentDraftMutationInterface
{
    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /** @param array<string, mixed> $stored */
    public function __construct(private readonly array $stored) {}

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return $this->stored;
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array {
        $this->writes[] = compact('id', 'values', 'expectedRevisionId', 'idempotencyKey');

        return ['id' => $id, 'revision_id' => $expectedRevisionId + 1, 'page_layout' => $values['page_layout']];
    }
}

final class InitialDocumentRevisionHistory implements ContentRevisionHistoryInterface
{
    public function revisions(AuthorizationPrincipalInterface $actor, string $id): array
    {
        return [
            ['revision_id' => 8, 'page_layout' => null, 'created_at' => '2026-08-13T12:00:00+00:00', 'is_current' => true, 'is_latest' => true],
            ['revision_id' => 9, 'page_layout' => '{"schema":"waaseyaa.layout"}'],
        ];
    }

    public function revision(AuthorizationPrincipalInterface $actor, string $id, int $revisionId): array
    {
        return ['id' => $id, 'revision_id' => $revisionId, 'page_layout' => null];
    }

    public function rollback(AuthorizationPrincipalInterface $actor, string $id, int $targetRevisionId, string $idempotencyKey, string $note = '', ?int $expectedCurrentRevisionId = null): array
    {
        return ['id' => $id, 'revision_id' => 10, 'page_layout' => null];
    }
}
