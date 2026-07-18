<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole;
use Waaseyaa\EntityStorage\Backend\SqlBlobBackend;
use Waaseyaa\EntityStorage\Backend\SqlColumnBackend;

#[CoversNothing]
final class FieldStorageGatewayArchitectureTest extends TestCase
{
    #[Test]
    public function v2_spi_has_only_the_fingerprinted_opaque_invocation_surface(): void
    {
        $interface = new \ReflectionClass(FieldStorageBackendV2Interface::class);

        $methods = array_map(static fn(\ReflectionMethod $method): string => $method->getName(), $interface->getMethods());
        sort($methods);
        self::assertSame(['fingerprint', 'id', 'invoke'], $methods);
        $invoke = $interface->getMethod('invoke');
        self::assertSame(FieldStorageGatewayRole::class, $invoke->getParameters()[0]->getType()?->getName());
        self::assertSame(FieldStorageGatewayInput::class, $invoke->getParameters()[1]->getType()?->getName());
        self::assertSame(FieldStorageGatewayOutput::class, $invoke->getReturnType()?->getName());
    }

    #[Test]
    public function registrar_exposes_a_gateway_but_no_raw_v2_implementation(): void
    {
        $registrar = new \ReflectionClass(BackendRegistrar::class);
        foreach ($registrar->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $return = $method->getReturnType();
            if ($return instanceof \ReflectionNamedType) {
                self::assertNotSame(FieldStorageBackendV2Interface::class, $return->getName(), $method->getName());
            }
        }

        self::assertSame(FieldStorageBackendGateway::class, $registrar->getMethod('gateway')->getReturnType()?->getName());
    }

    #[Test]
    public function production_backend_invocation_exists_only_inside_the_registrar_owned_gateway(): void
    {
        $sourceRoot = dirname(__DIR__, 3) . '/src';
        $calls = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, '$this->backend->invoke(')) {
                $calls[] = substr($file->getPathname(), strlen($sourceRoot) + 1);
            }
        }

        self::assertSame(['Backend/FieldStorageBackendGateway.php'], $calls);
    }

    #[Test]
    public function production_authority_and_gateway_construction_have_one_composition_root_each(): void
    {
        $sourceRoot = dirname(__DIR__, 3) . '/src';

        self::assertSame(
            ['Backend/FieldStorageBackendGateway.php'],
            $this->filesContaining($sourceRoot, 'new FieldStorageGatewayAuthority('),
        );
        self::assertSame(
            ['Backend/BackendRegistrar.php'],
            $this->filesContaining($sourceRoot, 'new FieldStorageBackendGateway('),
        );
        self::assertSame(
            ['Backend/FieldStorageGatewayAuthority.php'],
            $this->filesContaining($sourceRoot, 'FieldStorageGatewayRole::forAuthority('),
        );
    }

    #[Test]
    public function wp4_removes_the_v1_spi_and_raw_registrar_exposure_without_fallback(): void
    {
        $packageRoot = dirname(__DIR__, 3);
        foreach ([
            'src/Backend/FieldStorageBackendInterface.php',
            'src/Backend/HasFieldStorageBackendsInterface.php',
            'src/Backend/IsFrameworkBackendProviderInterface.php',
            'testing/Contract/FieldStorageBackendContractTestCase.php',
        ] as $relative) {
            self::assertFileDoesNotExist($packageRoot . '/' . $relative, $relative);
        }

        $publicMethods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(BackendRegistrar::class)->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        self::assertNotContains('get', $publicMethods);
        self::assertNotContains('all', $publicMethods);
        self::assertNotContains('v1BackendBlockers', $publicMethods);

        self::assertTrue(is_subclass_of(SqlBlobBackend::class, FieldStorageBackendV2Interface::class));
        self::assertTrue(is_subclass_of(SqlColumnBackend::class, FieldStorageBackendV2Interface::class));
    }

    #[Test]
    public function production_storage_consumers_reference_only_registrar_owned_gateways(): void
    {
        $sourceRoot = dirname(__DIR__, 3) . '/src';

        self::assertSame([], $this->filesContaining($sourceRoot, 'FieldStorageBackendInterface'));
        self::assertSame([], $this->filesContaining($sourceRoot, 'HasFieldStorageBackendsInterface'));
        self::assertSame([], $this->filesContaining($sourceRoot, 'IsFrameworkBackendProviderInterface'));
        self::assertSame(
            [
                'BackendResolver.php',
                'CoordinatorLifecycleDispatcher.php',
                'EntityStorageCoordinator.php',
            ],
            $this->filesContaining($sourceRoot, 'use Waaseyaa\\EntityStorage\\Backend\\FieldStorageBackendGateway;'),
        );
    }

    #[Test]
    public function coordinator_persistence_values_cross_only_the_private_closed_authority(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/CoordinatorLifecycleDispatcher.php');

        self::assertStringContainsString('private readonly \\Closure $persistenceValueAuthority;', $source);
        self::assertStringContainsString('EntityBase::class', $source);
        self::assertStringContainsString('$source->valueContainer->rawValues()', $source);
        self::assertStringNotContainsString('$entity->get($field->getName())', $source);
    }

    /** @return list<string> */
    private function filesContaining(string $sourceRoot, string $needle): array
    {
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $matches[] = substr($file->getPathname(), strlen($sourceRoot) + 1);
            }
        }

        sort($matches);

        return $matches;
    }
}
