<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigSyncFile;

#[CoversClass(ConfigSyncFile::class)]
final class ConfigSyncFileTest extends TestCase
{
    #[Test]
    public function constructsWithValidInputs(): void
    {
        $file = $this->makeFile();

        self::assertSame('role', $file->entityType);
        self::assertSame('coordinator', $file->entityId);
        self::assertSame('role.coordinator', $file->ref());
        self::assertSame('role.coordinator.yml', $file->filename());
    }

    #[Test]
    public function entityTypePatternIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'INVALID',
            entityId: 'admin',
            uuid: 'abc',
            dependencies: [],
            langcode: 'en',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function entityIdPatternIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'Has-Dash',
            uuid: 'abc',
            dependencies: [],
            langcode: 'en',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function emptyUuidRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: '',
            dependencies: [],
            langcode: 'en',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function emptyLangcodeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: 'u',
            dependencies: [],
            langcode: '',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function dependencyPatternIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: 'u',
            dependencies: ['NotAValidRef'],
            langcode: 'en',
            fields: [],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function fieldsMustBeSortedAlphabetically(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: 'u',
            dependencies: [],
            langcode: 'en',
            // Out-of-order: 'weight' appears before 'label'.
            fields: ['weight' => 10, 'label' => 'Admin'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    #[Test]
    public function contentHashIsDeterministic(): void
    {
        $a = $this->makeFile();
        $b = $this->makeFile();

        self::assertSame($a->contentHash(), $b->contentHash());
        // SHA-256 in hex is 64 chars.
        self::assertSame(64, \strlen($a->contentHash()));
    }

    #[Test]
    public function contentHashChangesWhenFieldsChange(): void
    {
        $a = $this->makeFile();
        $b = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: ['role.admin'],
            langcode: 'en',
            fields: [
                'description' => 'CHANGED',
                'id' => 'coordinator',
                'label' => 'Coordinator',
                'weight' => 10,
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        self::assertNotSame($a->contentHash(), $b->contentHash());
    }

    #[Test]
    public function legacyTransactionalContentHashDoesNotFoldInCfg03SchemaMetadata(): void
    {
        $baseline = $this->makeFile();
        $versioned = ConfigSyncFile::writable(
            entityType: $baseline->entityType,
            entityId: $baseline->entityId,
            uuid: $baseline->uuid,
            dependencies: $baseline->dependencies,
            langcode: $baseline->langcode,
            fields: $baseline->fields,
            schemaId: 'waaseyaa.role',
            schemaVersion: 7,
            schemaHash: 'sha256:' . str_repeat('a', 64),
            ownerPackage: 'waaseyaa/identity',
            ownerConfigContractVersion: 3,
        );

        self::assertSame(
            $baseline->contentHash(),
            $versioned->contentHash(),
            'CFG-02 persisted CAS identity remains backward-compatible; CFG-03 hashes use separate named APIs.',
        );
    }

    #[Test]
    public function splitFilenameHappyPath(): void
    {
        $parts = ConfigSyncFile::splitFilename('role.coordinator.yml');

        self::assertSame(['entity_type' => 'role', 'entity_id' => 'coordinator'], $parts);
    }

    #[Test]
    public function splitFilenameHandlesUnderscoreInEntityType(): void
    {
        $parts = ConfigSyncFile::splitFilename('taxonomy_vocabulary.community_categories.yml');

        self::assertSame(
            ['entity_type' => 'taxonomy_vocabulary', 'entity_id' => 'community_categories'],
            $parts,
        );
    }

    #[Test]
    public function splitFilenameRejectsMissingExtension(): void
    {
        $this->expectException(ConfigSerializationException::class);
        ConfigSyncFile::splitFilename('role.coordinator');
    }

    #[Test]
    public function splitFilenameRejectsBadSegmentPattern(): void
    {
        $this->expectException(ConfigSerializationException::class);
        ConfigSyncFile::splitFilename('Role.Admin.yml');
    }

    #[Test]
    public function fromParsedArrayRoundTrip(): void
    {
        $parsed = [
            '_meta' => [
                'dependencies' => ['role.admin'],
                'entity_id' => 'coordinator',
                'entity_type' => 'role',
                'format' => ConfigSyncFile::FORMAT_V1,
                'langcode' => 'en',
                'owner_config_contract_version' => 1,
                'owner_package' => 'waaseyaa/config',
                'schema_hash' => 'sha256:' . str_repeat('a', 64),
                'schema_id' => 'waaseyaa.role',
                'schema_version' => 1,
                'uuid' => '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            ],
            'description' => 'Coordinators manage community calendars.',
            'id' => 'coordinator',
            'label' => 'Coordinator',
            'weight' => 10,
        ];

        $file = ConfigSyncFile::fromParsedArray($parsed, 'role.coordinator.yml');

        self::assertSame('role', $file->entityType);
        self::assertSame('coordinator', $file->entityId);
        self::assertSame('0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f', $file->uuid);
        self::assertSame(['role.admin'], $file->dependencies);
        self::assertSame('en', $file->langcode);
        self::assertSame(
            ['description', 'id', 'label', 'weight'],
            array_keys($file->fields),
            'fromParsedArray sorts field keys alphabetically.',
        );
    }

    #[Test]
    public function fromParsedArrayRejectsMissingMetaBlock(): void
    {
        $this->expectException(ConfigSerializationException::class);
        ConfigSyncFile::fromParsedArray(['label' => 'no meta'], 'role.coordinator.yml');
    }

    #[Test]
    public function fromParsedArrayRejectsFilenameMismatch(): void
    {
        $parsed = [
            '_meta' => [
                'entity_type' => 'permission',
                'uuid' => 'u',
                'langcode' => 'en',
                'dependencies' => [],
            ],
        ];

        $this->expectException(ConfigSerializationException::class);
        ConfigSyncFile::fromParsedArray($parsed, 'role.coordinator.yml');
    }

    #[Test]
    public function fromParsedArrayRejectsMissingUuid(): void
    {
        $parsed = [
            '_meta' => [
                'entity_type' => 'role',
                'langcode' => 'en',
                'dependencies' => [],
            ],
        ];
        $this->expectException(ConfigSerializationException::class);
        ConfigSyncFile::fromParsedArray($parsed, 'role.coordinator.yml');
    }

    #[Test]
    public function fromParsedArrayRejectsMissingDependencies(): void
    {
        $parsed = [
            '_meta' => [
                'entity_id' => 'coordinator',
                'entity_type' => 'role',
                'format' => ConfigSyncFile::FORMAT_V1,
                'uuid' => 'u',
                'langcode' => 'en',
                'owner_config_contract_version' => 1,
                'owner_package' => 'waaseyaa/config',
                'schema_hash' => 'sha256:' . str_repeat('a', 64),
                'schema_id' => 'waaseyaa.role',
                'schema_version' => 1,
            ],
            'label' => 'Coordinator',
        ];

        $this->expectException(ConfigSerializationException::class);
        $this->expectExceptionMessage('dependencies');
        ConfigSyncFile::fromParsedArray($parsed, 'role.coordinator.yml');
    }

    #[Test]
    public function deterministicUuidIsStableAndUuidShaped(): void
    {
        $u1 = ConfigSyncFile::deterministicUuid('role', 'coordinator');
        $u2 = ConfigSyncFile::deterministicUuid('role', 'coordinator');

        self::assertSame($u1, $u2);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $u1,
            'deterministicUuid emits a UUID v5-shaped string.',
        );
    }

    #[Test]
    public function deterministicUuidDiffersAcrossInputs(): void
    {
        $a = ConfigSyncFile::deterministicUuid('role', 'admin');
        $b = ConfigSyncFile::deterministicUuid('role', 'coordinator');
        $c = ConfigSyncFile::deterministicUuid('permission', 'admin');

        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame($b, $c);
    }

    private function makeFile(): ConfigSyncFile
    {
        return ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: ['role.admin'],
            langcode: 'en',
            fields: [
                'description' => 'Coordinators manage community calendars.',
                'id' => 'coordinator',
                'label' => 'Coordinator',
                'weight' => 10,
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }
}
