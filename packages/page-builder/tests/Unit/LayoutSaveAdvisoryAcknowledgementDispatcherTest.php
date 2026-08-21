<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Draft\LayoutSaveAdvisoryAcknowledgementDispatcher;

/**
 * The single decision point for carrying acknowledgements through the layout
 * seam: legacy gateways keep the frozen call, receipts are never dropped, and
 * a gateway that cannot carry them is refused before any mutation.
 */
#[CoversClass(LayoutSaveAdvisoryAcknowledgementDispatcher::class)]
final class LayoutSaveAdvisoryAcknowledgementDispatcherTest extends TestCase
{
    private const string TOKEN_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string TOKEN_B = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    #[Test]
    public function no_receipts_calls_the_frozen_five_argument_gateway_method(): void
    {
        $gateway = $this->legacyGateway();

        $snapshot = LayoutSaveAdvisoryAcknowledgementDispatcher::update(
            $gateway,
            $this->actor(),
            '42',
            '{"v":1}',
            7,
            'edit-1',
        );

        self::assertSame(8, $snapshot->entityRevisionId);
        self::assertSame(1, $gateway->updateCalls);
        self::assertSame(
            5,
            $gateway->argumentCount,
            'A legacy gateway must never receive a sixth argument.',
        );
    }

    #[Test]
    public function receipts_reach_an_advisory_aware_gateway_verbatim(): void
    {
        $gateway = $this->advisoryAwareGateway();

        LayoutSaveAdvisoryAcknowledgementDispatcher::update(
            $gateway,
            $this->actor(),
            '42',
            '{"v":1}',
            7,
            'edit-1',
            [self::TOKEN_A, self::TOKEN_B],
        );

        self::assertSame([self::TOKEN_A, self::TOKEN_B], $gateway->receipts);
        self::assertSame('42', $gateway->entityId);
        self::assertSame(7, $gateway->expectedRevisionId);
        self::assertSame('edit-1', $gateway->idempotencyKey);
    }

    #[Test]
    public function an_advisory_aware_gateway_with_no_receipts_receives_an_empty_list_not_a_synthesized_one(): void
    {
        $gateway = $this->advisoryAwareGateway();

        LayoutSaveAdvisoryAcknowledgementDispatcher::update(
            $gateway,
            $this->actor(),
            '42',
            '{"v":1}',
            7,
            'edit-1',
        );

        self::assertSame([], $gateway->receipts, 'No receipt may be invented on the caller\'s behalf.');
    }

    #[Test]
    public function a_legacy_gateway_handed_receipts_is_refused_before_any_mutation(): void
    {
        $gateway = $this->legacyGateway();

        try {
            LayoutSaveAdvisoryAcknowledgementDispatcher::update(
                $gateway,
                $this->actor(),
                '42',
                '{"v":1}',
                7,
                'edit-1',
                [self::TOKEN_A],
            );
            self::fail('Receipts must never be dropped to make an update succeed.');
        } catch (UnsupportedLayoutSaveAdvisoryAcknowledgementException $exception) {
            self::assertSame(
                'SAVE_ADVISORY_UNSUPPORTED',
                UnsupportedLayoutSaveAdvisoryAcknowledgementException::ERROR_CODE,
            );
            self::assertStringNotContainsString(self::TOKEN_A, $exception->getMessage());
            self::assertStringNotContainsString($gateway::class, $exception->getMessage());
        }

        self::assertSame(0, $gateway->updateCalls, 'The refusal must land before the gateway is touched.');
    }

    #[Test]
    public function the_refusal_never_leaks_a_receipt_a_policy_code_or_an_implementation_name(): void
    {
        $exception = new UnsupportedLayoutSaveAdvisoryAcknowledgementException();
        $rendered = $exception->getMessage() . "\n" . $exception->getTraceAsString();

        self::assertStringNotContainsString(self::TOKEN_A, $rendered);
        self::assertStringNotContainsString(self::TOKEN_B, $rendered);
        self::assertSame(
            'This layout draft surface cannot accept save advisory acknowledgements.',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function the_dispatcher_accepts_the_base_contract_so_legacy_gateways_stay_supported(): void
    {
        $parameter = (new \ReflectionMethod(LayoutSaveAdvisoryAcknowledgementDispatcher::class, 'update'))
            ->getParameters()[0];

        self::assertSame('gateway', $parameter->getName());
        self::assertSame(
            LayoutDraftGatewayInterface::class,
            (string) $parameter->getType(),
            'Requiring the extension here would defeat the fail-closed path.',
        );
    }

    private function actor(): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(5, true, ['communications_officer'], ['edit pages'], 'test');
    }

    private function legacyGateway(): LegacyRecordingLayoutGateway
    {
        return new LegacyRecordingLayoutGateway();
    }

    private function advisoryAwareGateway(): AdvisoryAwareRecordingLayoutGateway
    {
        return new AdvisoryAwareRecordingLayoutGateway();
    }
}

/** A gateway frozen at the original five-argument contract. */
final class LegacyRecordingLayoutGateway implements LayoutDraftGatewayInterface
{
    public int $updateCalls = 0;
    public int $argumentCount = 0;

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        return new LayoutDraftSnapshot($entityId, 7, '{"v":0}');
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        ++$this->updateCalls;
        $this->argumentCount = func_num_args();

        return new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}

/** A gateway that opted into carrying receipts. */
final class AdvisoryAwareRecordingLayoutGateway implements AdvisoryAwareLayoutDraftGatewayInterface
{
    public int $updateCalls = 0;
    public string $entityId = '';
    public int $expectedRevisionId = 0;
    public string $idempotencyKey = '';
    /** @var list<string>|null */
    public ?array $receipts = null;

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        return new LayoutDraftSnapshot($entityId, 7, '{"v":0}');
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraftSnapshot {
        ++$this->updateCalls;
        $this->entityId = $entityId;
        $this->expectedRevisionId = $expectedRevisionId;
        $this->idempotencyKey = $idempotencyKey;
        $this->receipts = $saveAdvisoryAcknowledgements;

        return new LayoutDraftSnapshot($entityId, $expectedRevisionId + 1, $encodedLayout);
    }
}
