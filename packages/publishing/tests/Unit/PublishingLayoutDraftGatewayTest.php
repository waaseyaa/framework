<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;

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
