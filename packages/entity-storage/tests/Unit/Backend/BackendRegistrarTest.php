<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\BackendRegistrarFactory;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAuditReceipt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayFailure;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole;
use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;
use Waaseyaa\EntityStorage\Exception\BackendIdCollisionException;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(BackendRegistrar::class)]
#[CoversClass(BackendRegistrarFactory::class)]
#[CoversClass(BackendIdCollisionException::class)]
#[CoversClass(ReservedBackendIds::class)]
final class BackendRegistrarTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers — anonymous inline backend and provider factories
    // ---------------------------------------------------------------------------

    private function makeBackend(string $id): FieldStorageBackendV2Interface
    {
        return new class ($id) implements FieldStorageBackendV2Interface {
            public function __construct(private readonly string $backendId) {}

            public function id(): string
            {
                return $this->backendId;
            }

            public function fingerprint(): string
            {
                return hash('sha256', 'registrar-test:' . $this->backendId);
            }

            public function invoke(FieldStorageGatewayRole $gateway, FieldStorageGatewayInput $input): FieldStorageGatewayOutput
            {
                $gateway->unwrap($input, $this);

                return $gateway->complete($input, $this, null);
            }

            public function read(EntityInterface $entity, FieldDefinition $field): mixed
            {
                return null;
            }

            public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void {}

            public function delete(EntityInterface $entity): void {}

            public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
            {
                return false;
            }
        };
    }

    /**
     * @param FieldStorageBackendV2Interface[] $backends
     */
    private function makeProviderClass(array $backends, ?int $priority = null): string
    {
        // We need to generate a unique class at runtime that class_exists() can find.
        // We do this by defining a named class dynamically with a unique name.
        $className = 'WaaseyaaTestProvider_' . str_replace('.', '_', uniqid('', true));
        $priorityConst = $priority !== null ? "public const BACKEND_PRIORITY = {$priority};" : '';

        // Capture backends in a static registry keyed by class name so the
        // anonymous-class provider can retrieve them without closure capture.
        BackendProviderRegistry::set($className, $backends);

        $code = <<<PHP
                final class {$className} implements \Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface {
                    {$priorityConst}
                    public function fieldStorageBackendsV2(): array {
                        return \Waaseyaa\EntityStorage\Tests\Unit\Backend\BackendProviderRegistry::get('{$className}');
                    }
                }
            PHP;

        eval($code); // phpcs:ignore

        return $className;
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function two_providers_registering_same_non_reserved_id_raises_collision(): void
    {
        $backend1 = $this->makeBackend('my-custom-backend');
        $backend2 = $this->makeBackend('my-custom-backend');

        $providerA = $this->makeProviderClass([$backend1]);
        $providerB = $this->makeProviderClass([$backend2]);

        $registrar = new BackendRegistrar([$providerA, $providerB], gatewayAudit: new RegistrarTestAudit());

        $this->expectException(BackendIdCollisionException::class);
        $this->expectExceptionMessage('my-custom-backend');

        $registrar->build();
    }

    #[Test]
    public function third_party_registering_reserved_sql_blob_raises_collision(): void
    {
        $backend = $this->makeBackend(ReservedBackendIds::SQL_BLOB);
        $providerFqcn = $this->makeProviderClass([$backend]);

        // No framework provider FQCNs passed — this provider is third-party.
        $registrar = new BackendRegistrar([$providerFqcn], gatewayAudit: new RegistrarTestAudit());

        $this->expectException(BackendIdCollisionException::class);
        // New message: reserved-by-framework path (null $firstFqcn).
        $this->expectExceptionMessage('reserved by the framework');
        $this->expectExceptionMessage(ReservedBackendIds::SQL_BLOB);

        $registrar->build();
    }

    #[Test]
    public function third_party_registering_reserved_sql_column_raises_collision(): void
    {
        $backend = $this->makeBackend(ReservedBackendIds::SQL_COLUMN);
        $providerFqcn = $this->makeProviderClass([$backend]);

        $registrar = new BackendRegistrar([$providerFqcn], gatewayAudit: new RegistrarTestAudit());

        $this->expectException(BackendIdCollisionException::class);
        // New message: reserved-by-framework path (null $firstFqcn).
        $this->expectExceptionMessage('reserved by the framework');
        $this->expectExceptionMessage(ReservedBackendIds::SQL_COLUMN);

        $registrar->build();
    }

    #[Test]
    public function framework_provider_may_register_reserved_ids(): void
    {
        $backend = $this->makeBackend(ReservedBackendIds::SQL_BLOB);
        $providerFqcn = $this->makeProviderClass([$backend]);

        // Declare this provider as a framework provider.
        $registrar = new BackendRegistrar([$providerFqcn], [$providerFqcn], new RegistrarTestAudit());
        $registrar->build();

        self::assertTrue($registrar->has(ReservedBackendIds::SQL_BLOB));
    }

    #[Test]
    public function installed_json_order_determines_registration_order_absent_priority(): void
    {
        $backend1 = $this->makeBackend('alpha');
        $backend2 = $this->makeBackend('beta');

        $providerA = $this->makeProviderClass([$backend1]);
        $providerB = $this->makeProviderClass([$backend2]);

        // Pass in installed.json order: A first, B second.
        $registrar = new BackendRegistrar([$providerA, $providerB], gatewayAudit: new RegistrarTestAudit());
        $registrar->build();

        self::assertSame('alpha', $registrar->gateway('alpha')?->id());
        self::assertSame('beta', $registrar->gateway('beta')?->id());
        self::assertSame(['alpha', 'beta'], array_keys($registrar->gatewayFingerprints()));
    }

    #[Test]
    public function explicit_priority_constant_wins_over_default_order(): void
    {
        // providerLow comes first in installed.json order but has lower priority.
        $backendLow = $this->makeBackend('low-priority-backend');
        $backendHigh = $this->makeBackend('high-priority-backend');

        $providerLow = $this->makeProviderClass([$backendLow], priority: 10);
        $providerHigh = $this->makeProviderClass([$backendHigh], priority: 100);

        // Pass low first in order, but high has higher priority integer.
        $registrar = new BackendRegistrar([$providerLow, $providerHigh], gatewayAudit: new RegistrarTestAudit());
        $registrar->build();

        // After priority sort, high-priority provider registers first.
        $keys = array_keys($registrar->gatewayFingerprints());
        self::assertSame('high-priority-backend', $keys[0]);
        self::assertSame('low-priority-backend', $keys[1]);
    }

    #[Test]
    public function validate_field_backend_ids_throws_for_unknown_id(): void
    {
        $backend = $this->makeBackend('known-backend');
        $providerFqcn = $this->makeProviderClass([$backend], priority: null);

        $registrar = new BackendRegistrar([$providerFqcn], gatewayAudit: new RegistrarTestAudit());
        $registrar->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown-backend-id');

        $registrar->validateFieldBackendIds(['unknown-backend-id']);
    }

    #[Test]
    public function validate_field_backend_ids_passes_for_known_id(): void
    {
        $backend = $this->makeBackend('known-backend');
        $providerFqcn = $this->makeProviderClass([$backend]);

        $registrar = new BackendRegistrar([$providerFqcn], gatewayAudit: new RegistrarTestAudit());
        $registrar->build();

        // Must not throw.
        $registrar->validateFieldBackendIds(['known-backend']);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function collision_exception_exposes_public_properties(): void
    {
        $e = new BackendIdCollisionException('my-id', 'First\\Class', 'Second\\Class');

        self::assertSame('my-id', $e->backendId);
        self::assertSame('First\\Class', $e->firstFqcn);
        self::assertSame('Second\\Class', $e->secondFqcn);
        self::assertStringContainsString('my-id', $e->getMessage());
        self::assertStringContainsString('First\\Class', $e->getMessage());
        self::assertStringContainsString('Second\\Class', $e->getMessage());
    }

    #[Test]
    public function collision_exception_reserved_id_path_has_null_first_fqcn(): void
    {
        $e = new BackendIdCollisionException('sql-blob', null, 'Third\\Party\\Provider');

        self::assertSame('sql-blob', $e->backendId);
        self::assertNull($e->firstFqcn);
        self::assertSame('Third\\Party\\Provider', $e->secondFqcn);
        self::assertStringContainsString('reserved by the framework', $e->getMessage());
        self::assertStringContainsString('sql-blob', $e->getMessage());
        self::assertStringContainsString('Third\\Party\\Provider', $e->getMessage());
    }

    #[Test]
    public function reserved_backend_ids_all_returns_three_entries(): void
    {
        $all = ReservedBackendIds::all();

        self::assertContains(ReservedBackendIds::SQL_BLOB, $all);
        self::assertContains(ReservedBackendIds::SQL_COLUMN, $all);
        self::assertContains(ReservedBackendIds::VECTOR, $all);
        self::assertCount(3, $all);
    }

    // ---------------------------------------------------------------------------
    // BackendRegistrarFactory tests (Item 3)
    // ---------------------------------------------------------------------------

    /**
     * Generate a named provider class that also implements IsFrameworkBackendProviderV2Interface.
     *
     * @param FieldStorageBackendV2Interface[] $backends
     */
    private function makeFrameworkProviderClass(array $backends): string
    {
        $className = 'WaaseyaaFrameworkProvider_' . str_replace('.', '_', uniqid('', true));

        BackendProviderRegistry::set($className, $backends);

        $code = <<<PHP
                final class {$className}
                    implements \Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface {
                    public function fieldStorageBackendsV2(): array {
                        return \Waaseyaa\EntityStorage\Tests\Unit\Backend\BackendProviderRegistry::get('{$className}');
                    }
                }
            PHP;

        eval($code); // phpcs:ignore

        return $className;
    }

    #[Test]
    public function factory_discovers_all_providers_from_classmap(): void
    {
        $backend1 = $this->makeBackend('custom-a');
        $backend2 = $this->makeBackend('custom-b');

        $providerA = $this->makeProviderClass([$backend1]);
        $providerB = $this->makeProviderClass([$backend2]);

        $factory = new BackendRegistrarFactory([$providerA, $providerB], gatewayAudit: new RegistrarTestAudit());
        $registrar = $factory->create();
        $registrar->build();

        self::assertSame('custom-a', $registrar->gateway('custom-a')?->id());
        self::assertSame('custom-b', $registrar->gateway('custom-b')?->id());
        self::assertCount(2, $registrar->gatewayFingerprints());
    }

    #[Test]
    public function factory_identifies_framework_providers_via_marker_interface(): void
    {
        $backend = $this->makeBackend(ReservedBackendIds::SQL_BLOB);
        $frameworkProvider = $this->makeFrameworkProviderClass([$backend]);

        // Pass as a regular provider — factory detects IsFrameworkBackendProviderV2Interface.
        $factory = new BackendRegistrarFactory([$frameworkProvider], gatewayAudit: new RegistrarTestAudit());
        $registrar = $factory->create();
        $registrar->build();

        self::assertTrue($registrar->has(ReservedBackendIds::SQL_BLOB));
    }

    #[Test]
    public function factory_rejects_third_party_claiming_reserved_id(): void
    {
        $backend = $this->makeBackend(ReservedBackendIds::SQL_BLOB);
        $thirdPartyProvider = $this->makeProviderClass([$backend]); // NOT a framework provider.

        $factory = new BackendRegistrarFactory([$thirdPartyProvider], gatewayAudit: new RegistrarTestAudit());
        $registrar = $factory->create();

        $this->expectException(BackendIdCollisionException::class);
        $this->expectExceptionMessage('reserved by the framework');

        $registrar->build();
    }

    #[Test]
    public function factory_accepts_custom_instantiator(): void
    {
        $backend = $this->makeBackend('injected-backend');
        $providerFqcn = $this->makeProviderClass([$backend]);

        $instantiatorCalled = false;
        $instantiator = function (string $fqcn) use ($providerFqcn, &$instantiatorCalled): object {
            $instantiatorCalled = true;

            return new $fqcn();
        };

        $factory = new BackendRegistrarFactory([$providerFqcn], $instantiator, new RegistrarTestAudit());
        $registrar = $factory->create();
        $registrar->build();

        self::assertTrue($instantiatorCalled, 'Custom instantiator was not called.');
        self::assertTrue($registrar->has('injected-backend'));
    }
}

final class RegistrarTestAudit implements StrictFieldStorageGatewayAuditInterface
{
    public function reserve(FieldStorageGatewayAttempt $attempt): FieldStorageGatewayAuditReceipt
    {
        return new FieldStorageGatewayAuditReceipt($attempt);
    }

    public function succeed(FieldStorageGatewayAuditReceipt $receipt): void {}

    public function fail(FieldStorageGatewayAuditReceipt $receipt, FieldStorageGatewayFailure $failure): void {}
}

/**
 * Static registry so eval()'d provider classes can retrieve their backends
 * without relying on closure capture (which eval() cannot do).
 *
 * @internal Test helper only.
 */
final class BackendProviderRegistry
{
    /** @var array<string, FieldStorageBackendV2Interface[]> */
    private static array $backends = [];

    /** @param FieldStorageBackendV2Interface[] $backends */
    public static function set(string $className, array $backends): void
    {
        self::$backends[$className] = $backends;
    }

    /** @return FieldStorageBackendV2Interface[] */
    public static function get(string $className): array
    {
        return self::$backends[$className] ?? [];
    }
}
