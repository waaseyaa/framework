<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\StructuredImport\Gfm\GfmTableImporter;
use Waaseyaa\StructuredImport\Gfm\GfmTableParser;
use Waaseyaa\StructuredImport\Gfm\PromptNormalizer;
use Waaseyaa\StructuredImport\StructuredImporterInterface;

/**
 * Concrete contract test for GfmTableImporter.
 */
#[CoversNothing]
final class GfmTableImporterContractTest extends AbstractStructuredImporterContract
{
    protected function createImporter(): StructuredImporterInterface
    {
        $registry = new class implements FieldDefinitionRegistryInterface {
            public function bundleFieldsFor(string $entityTypeId, string $bundle): array
            {
                return [];
            }

            public function registerCoreFields(string $entityTypeId, array $fields): void {}

            public function mergeCoreFields(string $entityTypeId, array $fields): void {}

            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}

            public function coreFieldsFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundleNamesFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
            {
                return [];
            }
        };

        return new GfmTableImporter(
            registry: $registry,
            parser: new GfmTableParser(),
            normalizer: new PromptNormalizer(),
        );
    }
}
