<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\BackendRegistrarFactory;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAuditReceipt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayFailure;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOperation;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole;
use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;
use Waaseyaa\Field\FieldDefinition;

#[CoversNothing]
final class FieldStorageBackendGatewayTest extends TestCase
{
    #[Test]
    public function an_unissued_role_and_input_cannot_invoke_a_backend_directly(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('registrar-issued');

        $backend->invoke(new FieldStorageGatewayRole(), new FieldStorageGatewayInput());
    }

    #[Test]
    public function registered_gateway_round_trips_only_through_boundary_bound_inputs_and_outputs(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $audit = new GatewayTestAudit();
        $registrar = $this->registrar(v2: [$backend], audit: $audit);
        $entity = new GatewayTestEntity(['id' => '7', 'name' => 'before']);
        $field = new FieldDefinition(name: 'name', type: 'string');

        $gateway = $registrar->gateway('external');
        self::assertNotNull($gateway);
        self::assertSame('stored', $gateway->read($entity, $field));
        $gateway->write($entity, $field, 'after');

        self::assertSame(1, $backend->writeCount);
        self::assertSame(['after'], $backend->writtenValues);
        self::assertCount(2, $audit->successes);
        self::assertSame([], $audit->failures);
    }

    #[Test]
    public function fingerprint_drift_fails_before_backend_invocation_and_begins_no_write(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $audit = new GatewayTestAudit();
        $registrar = $this->registrar(v2: [$backend], audit: $audit);
        $backend->fingerprint = str_repeat('b', 64);

        try {
            $registrar->gateway('external')?->write(
                new GatewayTestEntity(['id' => '7', 'name' => 'before']),
                new FieldDefinition(name: 'name', type: 'string'),
                'after',
            );
            self::fail('Fingerprint drift must fail closed.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('fingerprint', $e->getMessage());
        }

        self::assertSame(0, $backend->invocationCount);
        self::assertSame(0, $backend->writeCount);
        self::assertCount(1, $audit->failures);
        self::assertSame(FieldStorageGatewayOperation::Write, $audit->failures[0]->attempt->operation);
        self::assertFalse($audit->failures[0]->backendInvocationStarted);
    }

    #[Test]
    public function strict_audit_reservation_failure_prevents_backend_write(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $audit = new GatewayTestAudit(reservationFailure: new \RuntimeException('audit unavailable'));
        $registrar = $this->registrar(v2: [$backend], audit: $audit);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('audit unavailable');

        try {
            $registrar->gateway('external')?->write(
                new GatewayTestEntity(['id' => '7']),
                new FieldDefinition(name: 'name', type: 'string'),
                'after',
            );
        } finally {
            self::assertSame(0, $backend->invocationCount);
            self::assertSame(0, $backend->writeCount);
        }
    }

    #[Test]
    public function an_audit_receipt_for_another_attempt_prevents_backend_write(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $audit = new GatewayTestAudit(mismatchedReceipt: true);
        $registrar = $this->registrar(v2: [$backend], audit: $audit);

        try {
            $registrar->gateway('external')?->write(
                new GatewayTestEntity(['id' => '7']),
                new FieldDefinition(name: 'name', type: 'string'),
                'after',
            );
            self::fail('Mismatched audit receipt must fail closed.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('different attempt', $e->getMessage());
        }

        self::assertSame(0, $backend->invocationCount);
        self::assertSame(0, $backend->writeCount);
    }

    #[Test]
    public function a_failure_after_backend_invocation_is_strictly_audited_as_potentially_partial(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $backend->writeFailure = new \RuntimeException('backend failed after starting');
        $audit = new GatewayTestAudit();
        $registrar = $this->registrar(v2: [$backend], audit: $audit);

        try {
            $registrar->gateway('external')?->write(
                new GatewayTestEntity(['id' => '7']),
                new FieldDefinition(name: 'name', type: 'string'),
                'after',
            );
            self::fail('Backend failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('backend failed after starting', $e->getMessage());
        }

        self::assertSame(1, $backend->writeCount);
        self::assertCount(1, $audit->failures);
        self::assertTrue($audit->failures[0]->backendInvocationStarted);
    }

    #[Test]
    public function registrar_exposes_exact_fingerprint_inventory_without_raw_v2_backends(): void
    {
        $v2 = new GatewayTestBackend('external', str_repeat('c', 64));
        $registrar = $this->registrar(v2: [$v2], audit: new GatewayTestAudit());

        self::assertSame(
            ['external' => GatewayTestBackend::class . ':' . str_repeat('c', 64)],
            $registrar->gatewayFingerprints(),
        );
        self::assertFalse(method_exists($registrar, 'v1BackendBlockers'));
        self::assertFalse(method_exists($registrar, 'getV2Backend'));
    }

    #[Test]
    public function malformed_v2_fingerprint_is_rejected_during_registration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64 lowercase hexadecimal');

        $this->registrar(v2: [new GatewayTestBackend('external', 'not-a-fingerprint')], audit: new GatewayTestAudit());
    }

    #[Test]
    public function v2_registration_without_strict_audit_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a strict gateway audit');

        $this->registrar(v2: [new GatewayTestBackend('external', str_repeat('a', 64))]);
    }

    #[Test]
    public function preflight_inventory_validates_fingerprints_without_issuing_gateway_authority(): void
    {
        GatewayTestProvider::$v2 = [new GatewayTestBackend('external', str_repeat('d', 64))];
        $registrar = new BackendRegistrar([GatewayTestProvider::class]);

        $registrar->buildPreflightInventory();

        self::assertSame(
            ['external' => GatewayTestBackend::class . ':' . str_repeat('d', 64)],
            $registrar->gatewayFingerprints(),
        );
        self::assertNull($registrar->gateway('external'));
    }

    #[Test]
    public function registrar_factory_discovers_a_v2_only_provider_and_threads_strict_audit(): void
    {
        GatewayTestV2OnlyProvider::$v2 = [new GatewayTestBackend('external', str_repeat('e', 64))];
        $registrar = new BackendRegistrarFactory(
            [GatewayTestV2OnlyProvider::class],
            gatewayAudit: new GatewayTestAudit(),
        )->create();

        $registrar->build();

        self::assertNotNull($registrar->gateway('external'));
        self::assertSame(
            ['external' => GatewayTestBackend::class . ':' . str_repeat('e', 64)],
            $registrar->gatewayFingerprints(),
        );
    }

    #[Test]
    public function a_backend_cannot_return_an_output_it_constructed_outside_the_issuing_boundary(): void
    {
        $backend = new GatewayTestBackend('external', str_repeat('a', 64));
        $backend->forgeOutput = true;
        $audit = new GatewayTestAudit();
        $registrar = $this->registrar(v2: [$backend], audit: $audit);

        try {
            $registrar->gateway('external')?->read(
                new GatewayTestEntity(['id' => '7']),
                new FieldDefinition(name: 'name', type: 'string'),
            );
            self::fail('A forged output must fail closed.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('boundary-bound', $e->getMessage());
        }

        self::assertCount(1, $audit->failures);
        self::assertTrue($audit->failures[0]->backendInvocationStarted);
    }

    /**
     * @param list<FieldStorageBackendV2Interface> $v2
     */
    private function registrar(array $v2 = [], ?StrictFieldStorageGatewayAuditInterface $audit = null): BackendRegistrar
    {
        GatewayTestProvider::$v2 = $v2;
        $registrar = new BackendRegistrar([GatewayTestProvider::class], [], $audit);
        $registrar->build();

        return $registrar;
    }
}

final class GatewayTestProvider implements HasFieldStorageBackendsV2Interface
{
    /** @var list<FieldStorageBackendV2Interface> */
    public static array $v2 = [];

    public function fieldStorageBackendsV2(): array
    {
        return self::$v2;
    }
}

final class GatewayTestV2OnlyProvider implements HasFieldStorageBackendsV2Interface
{
    /** @var list<FieldStorageBackendV2Interface> */
    public static array $v2 = [];

    public function fieldStorageBackendsV2(): array
    {
        return self::$v2;
    }
}

final class GatewayTestBackend implements FieldStorageBackendV2Interface
{
    public int $invocationCount = 0;
    public int $writeCount = 0;
    /** @var list<mixed> */
    public array $writtenValues = [];
    public ?\Throwable $writeFailure = null;
    public bool $forgeOutput = false;

    public function __construct(private readonly string $backendId, public string $fingerprint) {}
    public function id(): string
    {
        return $this->backendId;
    }
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function invoke(FieldStorageGatewayRole $gateway, FieldStorageGatewayInput $input): FieldStorageGatewayOutput
    {
        $call = $gateway->unwrap($input, $this);
        ++$this->invocationCount;
        if ($this->forgeOutput) {
            return new FieldStorageGatewayOutput();
        }
        if ($call->operation === FieldStorageGatewayOperation::Write) {
            ++$this->writeCount;
            $this->writtenValues[] = $call->value;
            if ($this->writeFailure !== null) {
                throw $this->writeFailure;
            }
            return $gateway->complete($input, $this, null);
        }
        if ($call->operation === FieldStorageGatewayOperation::Read) {
            return $gateway->complete($input, $this, 'stored');
        }
        return $gateway->complete($input, $this, true);
    }
}

final class GatewayTestAudit implements StrictFieldStorageGatewayAuditInterface
{
    /** @var list<FieldStorageGatewayAuditReceipt> */
    public array $successes = [];
    /** @var list<FieldStorageGatewayFailure> */
    public array $failures = [];

    public function __construct(
        private readonly ?\Throwable $reservationFailure = null,
        private readonly bool $mismatchedReceipt = false,
    ) {}

    public function reserve(FieldStorageGatewayAttempt $attempt): FieldStorageGatewayAuditReceipt
    {
        if ($this->reservationFailure !== null) {
            throw $this->reservationFailure;
        }
        if ($this->mismatchedReceipt) {
            $attempt = new FieldStorageGatewayAttempt(
                'other',
                str_repeat('f', 64),
                $attempt->operation,
                $attempt->entityTypeId,
                $attempt->entityId,
                $attempt->fieldName,
            );
        }

        return new FieldStorageGatewayAuditReceipt($attempt);
    }

    public function succeed(FieldStorageGatewayAuditReceipt $receipt): void
    {
        $this->successes[] = $receipt;
    }
    public function fail(FieldStorageGatewayAuditReceipt $receipt, FieldStorageGatewayFailure $failure): void
    {
        $this->failures[] = $failure;
    }
}

final class GatewayTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'gateway_test', ['id' => 'id']);
    }
}
