<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigContentHashes;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;

#[CoversClass(ConfigContentHasher::class)]
#[CoversClass(ConfigContentHashes::class)]
final class ConfigContentHasherTest extends TestCase
{
    #[Test]
    public function exactAuthoredAndEffectiveIdentitiesAreDistinctAndSchemaBound(): void
    {
        [$registry, $file] = $this->fixture();
        $yaml = new ConfigSyncSerializer()->toYaml($file);

        $hashes = new ConfigContentHasher()->hash($file, $yaml, $registry);

        self::assertNotSame($hashes->exactByteHash, $hashes->authoredContentHash);
        self::assertNotSame($hashes->authoredContentHash, $hashes->effectiveEntryHash);
        self::assertSame(['title' => 'Waaseyaa', 'enabled' => false], $hashes->effectiveFields);
    }

    #[Test]
    public function commentsChangeOnlyExactByteIdentity(): void
    {
        [$registry, $file] = $this->fixture();
        $yaml = new ConfigSyncSerializer()->toYaml($file);
        $hasher = new ConfigContentHasher();

        $plain = $hasher->hash($file, $yaml, $registry);
        $commented = $hasher->hash($file, "# reviewed\n" . $yaml, $registry);

        self::assertNotSame($plain->exactByteHash, $commented->exactByteHash);
        self::assertSame($plain->authoredContentHash, $commented->authoredContentHash);
        self::assertSame($plain->effectiveEntryHash, $commented->effectiveEntryHash);
    }

    #[Test]
    public function unfrozenOrMismatchedRegistryIdentityIsRejected(): void
    {
        [$registry, $file] = $this->fixture(freeze: false);
        $yaml = new ConfigSyncSerializer()->toYaml($file);

        try {
            new ConfigContentHasher()->hash($file, $yaml, $registry);
            self::fail('Unfrozen registry was accepted.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $registry->freeze();
        $mismatched = ConfigSyncFile::writable(
            entityType: $file->entityType,
            entityId: $file->entityId,
            uuid: $file->uuid,
            dependencies: $file->dependencies,
            langcode: $file->langcode,
            fields: $file->fields,
            schemaId: $file->schemaId,
            schemaVersion: $file->schemaVersion,
            schemaHash: 'sha256:' . str_repeat('b', 64),
            ownerPackage: $file->ownerPackage,
            ownerConfigContractVersion: $file->ownerConfigContractVersion,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match the frozen registry');
        new ConfigContentHasher()->hash($mismatched, new ConfigSyncSerializer()->toYaml($mismatched), $registry);
    }

    /** @return array{ConfigSchemaRegistry, ConfigSyncFile} */
    private function fixture(bool $freeze = true): array
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'title' => ['type' => 'string'],
            ],
            'required' => ['title'],
        ]);
        if ($freeze) {
            $registry->freeze();
        }

        return [$registry, ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['title' => 'Waaseyaa'],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        )];
    }
}
