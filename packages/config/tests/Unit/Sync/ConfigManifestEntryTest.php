<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigManifestEntry;
use Waaseyaa\Config\Sync\ConfigSyncFile;

#[CoversClass(ConfigManifestEntry::class)]
final class ConfigManifestEntryTest extends TestCase
{
    #[Test]
    public function carriesAllConstructorFields(): void
    {
        $entry = new ConfigManifestEntry(
            ref: 'role.coordinator',
            entityType: 'role',
            entityId: 'coordinator',
            uuid: 'u',
            path: '/abs/role.coordinator.yml',
            contentHash: hash('sha256', 'x'),
            mtime: 1700000000,
        );

        self::assertSame('role.coordinator', $entry->ref);
        self::assertSame('role', $entry->entityType);
        self::assertSame('coordinator', $entry->entityId);
        self::assertSame('u', $entry->uuid);
        self::assertSame('/abs/role.coordinator.yml', $entry->path);
        self::assertSame(hash('sha256', 'x'), $entry->contentHash);
        self::assertSame(1700000000, $entry->mtime);
    }

    #[Test]
    public function fromSyncFileMirrorsRefAndHash(): void
    {
        $file = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: '0193abcd-7c4d-7000-8b6e-1a2b3c4d5e6f',
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Coordinator'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        $entry = ConfigManifestEntry::fromSyncFile($file, '/abs/role.coordinator.yml', 42);

        self::assertSame($file->ref(), $entry->ref);
        self::assertSame($file->uuid, $entry->uuid);
        self::assertSame($file->contentHash(), $entry->contentHash);
        self::assertSame('/abs/role.coordinator.yml', $entry->path);
        self::assertSame(42, $entry->mtime);
    }
}
