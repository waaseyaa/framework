<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\StructuredImport\Gfm\GfmTableImporter;
use Waaseyaa\StructuredImport\StructuredImportServiceProvider;
use Waaseyaa\StructuredImport\StructuredImporterInterface;

#[CoversClass(StructuredImportServiceProvider::class)]
final class StructuredImportServiceProviderTest extends TestCase
{
    #[Test]
    public function resolves_the_structured_importer_without_a_fatal(): void
    {
        // Pre-fix the interface was bound to the class NAME (a string), which the
        // resolver instantiates with `new $concrete()` (zero args) — a TypeError
        // because GfmTableImporter requires three constructor dependencies.
        $provider = new StructuredImportServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->setKernelServices($this->kernelServicesProviding(
            FieldDefinitionRegistryInterface::class,
            $this->createMock(FieldDefinitionRegistryInterface::class),
        ));
        $provider->register();

        $importer = $provider->resolve(StructuredImporterInterface::class);

        self::assertInstanceOf(GfmTableImporter::class, $importer);
    }

    private function kernelServicesProviding(string $abstract, object $service): KernelServicesInterface
    {
        return new class ($abstract, $service) implements KernelServicesInterface {
            public function __construct(
                private readonly string $abstract,
                private readonly object $service,
            ) {}

            public function get(string $abstract): ?object
            {
                return $abstract === $this->abstract ? $this->service : null;
            }
        };
    }
}
