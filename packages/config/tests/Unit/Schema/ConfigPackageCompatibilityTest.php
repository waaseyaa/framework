<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\LegacyConfigSyncMigrator;

final class ConfigPackageCompatibilityTest extends TestCase
{
    #[Test]
    public function packageManifestDeclaresClosedWritableAndReadableContract(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);

        $contract = ConfigPackageContract::fromComposerManifest($composer);

        self::assertSame('waaseyaa/config', $contract->package);
        self::assertSame(1, $contract->writableContractVersion);
        self::assertSame([1], $contract->readableContractVersions);
    }

    #[Test]
    public function retainedReadableVersionIsNotAcceptedForNewWrites(): void
    {
        [$registry, $old, $current] = $this->registry();
        $compatibility = new ConfigPackageCompatibility([
            new ConfigPackageContract('waaseyaa/config', 2, 'test.Provider', [1, 2]),
        ]);
        $oldFile = $this->file($old, ['title' => 'Old']);
        $currentFile = $this->file($current, ['title' => 'Current']);

        $compatibility->assertReadable($oldFile, $registry);
        $compatibility->assertWritable($currentFile, $registry);
        self::addToAssertionCount(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not the installed writable version');
        $compatibility->assertWritable($oldFile, $registry);
    }

    #[Test]
    public function formatZeroMigrationIsExplicitDeterministicAndForwardOnly(): void
    {
        [$registry, , $current] = $this->registry();
        $compatibility = new ConfigPackageCompatibility([
            new ConfigPackageContract('waaseyaa/config', 2, 'test.Provider', [1, 2]),
        ]);
        $legacy = ConfigSyncFile::legacyReadable(
            'system',
            'site',
            ConfigSyncFile::deterministicUuid('system', 'site'),
            [],
            'en',
            ['title' => 'Migrated'],
        );
        $migrator = new LegacyConfigSyncMigrator($registry, $compatibility);

        $first = $migrator->migrate($legacy, $current->schemaId, $current->schemaVersion);
        $second = $migrator->migrate($legacy, $current->schemaId, $current->schemaVersion);

        self::assertTrue($first->isWritableV1());
        self::assertSame($legacy->contentHash(), $first->contentHash(), 'CFG-02 legacy content identity must remain stable.');
        self::assertSame(new ConfigSyncSerializer()->toYaml($first), new ConfigSyncSerializer()->toYaml($second));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only explicit format-0');
        $migrator->migrate($first, $current->schemaId, $current->schemaVersion);
    }

    /** @return array{ConfigSchemaRegistry, \Waaseyaa\Config\Schema\ConfigSchemaRegistration, \Waaseyaa\Config\Schema\ConfigSchemaRegistration} */
    private function registry(): array
    {
        $registry = new ConfigSchemaRegistry();
        $schema = [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
        ];
        $old = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, $schema);
        $current = $registry->register('waaseyaa.system.site', 2, 'waaseyaa/config', 2, $schema);
        $registry->freeze();

        return [$registry, $old, $current];
    }

    /** @param array<string, mixed> $fields */
    private function file(\Waaseyaa\Config\Schema\ConfigSchemaRegistration $registration, array $fields): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            'system',
            'site',
            ConfigSyncFile::deterministicUuid('system', 'site'),
            [],
            'en',
            $fields,
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
    }
}
