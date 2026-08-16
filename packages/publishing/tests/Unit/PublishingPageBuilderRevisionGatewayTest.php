<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\ContentRevisionHistoryInterface;
use Waaseyaa\Publishing\PageBuilder\PublishingPageBuilderRevisionGateway;

#[CoversClass(PublishingPageBuilderRevisionGateway::class)]
final class PublishingPageBuilderRevisionGatewayTest extends TestCase
{
    #[Test]
    public function itMapsHistoryRevisionReadsAndGuardedRestore(): void
    {
        $actor = new class implements AuthorizationPrincipalInterface {
            public function id(): string|int { return '7'; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['editor']; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
        $publisher = new class implements ContentRevisionHistoryInterface {
            /** @var list<mixed> */
            public array $restoreArgs = [];
            public function revisions(AuthorizationPrincipalInterface $actor, string $id): array
            {
                return [['revision_id' => 8, 'page_layout' => '{"schema":"waaseyaa.layout"}', 'created_at' => '2026-08-13T12:00:00+00:00', 'author_uid' => 7, 'log' => 'Edit', 'status' => false, 'is_current' => true, 'is_latest' => true]];
            }
            public function revision(AuthorizationPrincipalInterface $actor, string $id, int $revisionId): array
            {
                return ['id' => $id, 'revision_id' => $revisionId, 'page_layout' => '{"schema":"waaseyaa.layout"}'];
            }
            public function rollback(AuthorizationPrincipalInterface $actor, string $id, int $targetRevisionId, string $idempotencyKey, string $note = '', ?int $expectedCurrentRevisionId = null): array
            {
                $this->restoreArgs = [$id, $targetRevisionId, $idempotencyKey, $expectedCurrentRevisionId];
                return ['id' => $id, 'revision_id' => 9, 'page_layout' => '{"schema":"waaseyaa.layout"}'];
            }
        };
        $gateway = new PublishingPageBuilderRevisionGateway($publisher, 'page_layout');

        $history = $gateway->list($actor, '42');
        $revision = $gateway->readRevision($actor, '42', 8);
        $restored = $gateway->restore($actor, '42', 8, 8, 'restore-1');

        self::assertSame(8, $history[0]->revisionId);
        self::assertTrue($history[0]->isCurrent);
        self::assertSame('{"schema":"waaseyaa.layout"}', $revision->encodedLayout);
        self::assertSame(9, $restored->entityRevisionId);
        self::assertSame(['42', 8, 'restore-1', 8], $publisher->restoreArgs);
    }
}
