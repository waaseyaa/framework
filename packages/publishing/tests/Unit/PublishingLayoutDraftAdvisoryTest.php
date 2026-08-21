<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\Publishing\AdvisoryAwareContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException;
use Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;

/**
 * The publishing-backed layout gateway is the bridge between the two seams: it
 * opts into carrying receipts, hands them to the draft-mutation dispatcher, and
 * translates an unacknowledged advisory into the page-builder-typed outcome.
 */
#[CoversClass(PublishingLayoutDraftGateway::class)]
final class PublishingLayoutDraftAdvisoryTest extends TestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function the_framework_gateway_implements_the_aware_extension(): void
    {
        self::assertTrue(
            is_subclass_of(PublishingLayoutDraftGateway::class, AdvisoryAwareLayoutDraftGatewayInterface::class),
            'The framework gateway must carry receipts, or no first-party page builder can acknowledge one.',
        );
    }

    #[Test]
    public function receipts_are_forwarded_verbatim_to_the_draft_mutation_seam(): void
    {
        $publisher = new AdvisoryAwarePublisher();
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout');

        $gateway->update($this->actor(), '42', '{"v":1}', 7, 'edit-1', [self::TOKEN]);

        self::assertSame([self::TOKEN], $publisher->receipts);
        self::assertSame(['page_layout' => '{"v":1}'], $publisher->values);
    }

    #[Test]
    public function an_update_without_receipts_reaches_the_frozen_five_argument_publisher(): void
    {
        $publisher = new LegacyPublisher();
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout');

        $snapshot = $gateway->update($this->actor(), '42', '{"v":1}', 7, 'edit-1');

        self::assertSame(8, $snapshot->entityRevisionId);
        self::assertSame(5, $publisher->argumentCount);
    }

    #[Test]
    public function receipts_handed_to_a_legacy_publisher_are_refused_as_a_layout_typed_outcome(): void
    {
        $publisher = new LegacyPublisher();
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout');

        try {
            $gateway->update($this->actor(), '42', '{"v":1}', 7, 'edit-1', [self::TOKEN]);
            self::fail('Receipts must never be dropped to make an update succeed.');
        } catch (UnsupportedLayoutSaveAdvisoryAcknowledgementException $exception) {
            // A page-builder transport catches only the layout contract:
            // waaseyaa/admin-surface has no dependency on this package. Letting
            // the publishing refusal escape would bypass the promised
            // structured 501 SAVE_ADVISORY_UNSUPPORTED entirely.
            self::assertSame(
                'SAVE_ADVISORY_UNSUPPORTED',
                UnsupportedLayoutSaveAdvisoryAcknowledgementException::ERROR_CODE,
            );
            self::assertInstanceOf(
                UnsupportedSaveAdvisoryAcknowledgementException::class,
                $exception->getPrevious(),
                'The originating refusal is chained for diagnosis.',
            );
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString($publisher::class, $exception->getMessage());
            self::assertStringNotContainsString('Publishing', $exception->getMessage());
        }

        self::assertSame(0, $publisher->updateCalls, 'The refusal must land before any write.');
    }

    #[Test]
    public function an_unacknowledged_advisory_becomes_the_page_builder_typed_outcome_with_payloads_intact(): void
    {
        $publisher = new HoldingPublisher();
        $gateway = new PublishingLayoutDraftGateway($publisher, 'page_layout');

        try {
            $gateway->update($this->actor(), '42', '{"v":1}', 7, 'edit-1');
            self::fail('An unacknowledged advisory must not write.');
        } catch (LayoutSaveAdvisoryException $exception) {
            $payload = $exception->advisoryPayloads()[0];

            self::assertSame('EDITORIAL_TITLE_REVIEW', $payload['code']);
            self::assertSame('title', $payload['field']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['acknowledgement']);
            self::assertInstanceOf(ContentSaveAdvisoryException::class, $exception->getPrevious());
        }
    }

    private function actor(): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }
}

final class AdvisoryAwarePublisher implements AdvisoryAwareContentDraftMutationInterface
{
    /** @var list<string>|null */
    public ?array $receipts = null;
    /** @var array<string, mixed> */
    public array $values = [];

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7, 'page_layout' => '{"v":0}'];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): array {
        $this->receipts = $saveAdvisoryAcknowledgements;
        $this->values = $values;

        return ['id' => $id, 'revision_id' => $expectedRevisionId + 1] + $values;
    }
}

final class LegacyPublisher implements ContentDraftMutationInterface
{
    public int $updateCalls = 0;
    public int $argumentCount = 0;

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7, 'page_layout' => '{"v":0}'];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array {
        ++$this->updateCalls;
        $this->argumentCount = func_num_args();

        return ['id' => $id, 'revision_id' => $expectedRevisionId + 1] + $values;
    }
}

/** Raises a real candidate-bound advisory rather than a hand-written payload. */
final class HoldingPublisher implements AdvisoryAwareContentDraftMutationInterface
{
    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7, 'page_layout' => '{"v":0}'];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): array {
        $entity = new \Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity([
            'title' => 'Held title',
            'slug' => 'held',
        ]);

        throw new ContentSaveAdvisoryException(
            new \Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException([
                SaveAdvisory::forEntityField($entity, 'EDITORIAL_TITLE_REVIEW', 'title', 'Held for review.'),
            ]),
        );
    }
}
