<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\AdvisoryAwareContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\Exception\ContentPublishingException;
use Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException;
use Waaseyaa\Publishing\SaveAdvisoryAcknowledgementDispatcher;

/**
 * Source compatibility for the draft-mutation seam.
 *
 * #2467's first shape added an optional parameter directly to
 * ContentDraftMutationInterface::updateDraft(). PHP requires an implementor to
 * declare every parameter the interface declares, so that addition was a
 * load-time fatal for every application already implementing the seam. These
 * regressions pin the sub-interface shape that replaced it.
 */
#[CoversClass(SaveAdvisoryAcknowledgementDispatcher::class)]
#[CoversClass(UnsupportedSaveAdvisoryAcknowledgementException::class)]
final class SaveAdvisoryAcknowledgementCompatibilityTest extends TestCase
{
    private const TOKEN_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const TOKEN_B = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    #[Test]
    public function legacyFiveParameterImplementorLoadsWithoutADeclarationFatal(): void
    {
        $legacy = new LegacyDraftMutation();

        self::assertInstanceOf(ContentDraftMutationInterface::class, $legacy);
        self::assertNotInstanceOf(AdvisoryAwareContentDraftMutationInterface::class, $legacy);
    }

    #[Test]
    public function theFrameworkPublisherIsAdvisoryAware(): void
    {
        self::assertTrue(
            is_subclass_of(ContentPublisher::class, AdvisoryAwareContentDraftMutationInterface::class),
            'ContentPublisher must implement the advisory-aware sub-interface.',
        );
        self::assertTrue(
            is_subclass_of(AdvisoryAwareContentDraftMutationInterface::class, ContentDraftMutationInterface::class),
            'The aware sub-interface must extend the frozen base contract.',
        );
    }

    #[Test]
    public function withoutAcknowledgementsTheOrdinaryFiveArgumentMethodIsCalled(): void
    {
        $legacy = new LegacyDraftMutation();

        $result = SaveAdvisoryAcknowledgementDispatcher::updateDraft(
            $legacy,
            $this->actor(),
            '42',
            ['title' => 'Unchanged behaviour'],
            7,
            'idem-key-legacy',
        );

        self::assertSame(['id' => '42', 'revision_id' => 8], $result);
        self::assertSame(1, $legacy->calls);
        self::assertSame(['title' => 'Unchanged behaviour'], $legacy->values);
        self::assertSame(7, $legacy->expectedRevisionId);
        self::assertSame('idem-key-legacy', $legacy->idempotencyKey);
    }

    #[Test]
    public function anAwareImplementationReceivesTheExactAcknowledgements(): void
    {
        $aware = new AwareDraftMutation();

        SaveAdvisoryAcknowledgementDispatcher::updateDraft(
            $aware,
            $this->actor(),
            '42',
            ['slug' => 'news'],
            7,
            'idem-key-aware',
            [self::TOKEN_A, self::TOKEN_B],
        );

        self::assertSame([self::TOKEN_A, self::TOKEN_B], $aware->acknowledgements);
        self::assertSame(['slug' => 'news'], $aware->values);
    }

    #[Test]
    public function anAwareImplementationCalledWithoutAcknowledgementsSeesTheDefault(): void
    {
        $aware = new AwareDraftMutation();

        SaveAdvisoryAcknowledgementDispatcher::updateDraft(
            $aware,
            $this->actor(),
            '42',
            ['slug' => 'ordinary'],
            7,
            'idem-key-default',
        );

        self::assertSame([], $aware->acknowledgements);
    }

    #[Test]
    public function anonAwareImplementationWithAcknowledgementsFailsClosed(): void
    {
        $legacy = new LegacyDraftMutation();

        try {
            SaveAdvisoryAcknowledgementDispatcher::updateDraft(
                $legacy,
                $this->actor(),
                '42',
                ['slug' => 'news'],
                7,
                'idem-key-refused',
                [self::TOKEN_A],
            );
            self::fail('Acknowledgements must not reach a surface that cannot carry them.');
        } catch (UnsupportedSaveAdvisoryAcknowledgementException $exception) {
            self::assertSame(0, $legacy->calls, 'No write may be attempted when receipts cannot be carried.');
            self::assertInstanceOf(ContentPublishingException::class, $exception);
            self::assertSame('SAVE_ADVISORY_UNSUPPORTED', $exception->errorCode);
        }
    }

    #[Test]
    public function theRefusalLeaksNoTokenPolicyOrImplementationDetail(): void
    {
        $exception = new UnsupportedSaveAdvisoryAcknowledgementException();
        $rendered = $exception->getMessage() . '|' . json_encode([
            'code' => $exception->errorCode,
            'fields' => $exception->fieldErrors,
            'meta' => $exception->meta,
        ], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::TOKEN_A, $rendered);
        self::assertStringNotContainsString(self::TOKEN_B, $rendered);
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $rendered);
        self::assertStringNotContainsString('\\', $rendered, 'No FQCN may reach the transport.');
        self::assertStringNotContainsString('LegacyDraftMutation', $rendered);
        self::assertStringNotContainsString('ContentPublisher', $rendered);
        self::assertSame([], $exception->fieldErrors);
        self::assertSame([], $exception->meta);
    }

    private function actor(): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }
}

/** A pre-#2467 implementor: exactly the five frozen parameters. */
final class LegacyDraftMutation implements ContentDraftMutationInterface
{
    public int $calls = 0;
    /** @var array<string, mixed> */
    public array $values = [];
    public ?int $expectedRevisionId = null;
    public ?string $idempotencyKey = null;

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array {
        ++$this->calls;
        $this->values = $values;
        $this->expectedRevisionId = $expectedRevisionId;
        $this->idempotencyKey = $idempotencyKey;

        return ['id' => $id, 'revision_id' => 8];
    }
}

/** An implementor that opted into carrying receipts. */
final class AwareDraftMutation implements AdvisoryAwareContentDraftMutationInterface
{
    /** @var array<string, mixed> */
    public array $values = [];
    /** @var array<int|string, mixed> */
    public array $acknowledgements = [];

    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        return ['id' => $idOrSlug, 'revision_id' => 7];
    }

    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): array {
        $this->values = $values;
        $this->acknowledgements = $saveAdvisoryAcknowledgements;

        return ['id' => $id, 'revision_id' => 8];
    }
}
