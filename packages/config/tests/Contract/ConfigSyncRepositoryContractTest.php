<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

/**
 * Contract test for {@see ConfigSyncRepository}.
 *
 * Verifies the stable-surface commitments declared in
 * `kitty-specs/config-management-v1-01KRCDEC/contracts/active-sync-store.md`:
 *  - Default per-file naming convention (`<entity_type>.<entity_id>.yml`).
 *  - Atomic write via temp-then-rename.
 *  - Warn-and-skip behaviour on files outside the naming convention.
 *  - `list()`, `get()`, `put()`, `delete()`, `has()`, `syncPath()` surface.
 *
 * Marked `@CoversNothing` because contract tests document API shape; the
 * concrete unit tests cover the implementation.
 */
#[CoversNothing]
final class ConfigSyncRepositoryContractTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_sync_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    #[Test]
    public function syncPathIsReturnedVerbatim(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        self::assertSame($this->tempDir, $repo->syncPath());
    }

    #[Test]
    public function putWritesAtomically(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $file = $this->makeFile();

        $repo->put($file);

        $target = $this->tempDir . '/role.coordinator.yml';
        self::assertFileExists($target);

        // No legacy or uniquely-named temp file is left behind.
        self::assertFileDoesNotExist($target . '.tmp');
        self::assertSame([], glob($target . '.tmp-*') ?: []);

        $contents = file_get_contents($target);
        self::assertIsString($contents);
        self::assertStringStartsWith('_meta:', $contents);
        self::assertStringEndsWith("\n", $contents);
    }

    #[Test]
    public function getReturnsNullWhenMissing(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        self::assertNull($repo->get('role.coordinator'));
    }

    #[Test]
    public function getRoundTripsAfterPut(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $file = $this->makeFile();
        $repo->put($file);

        $loaded = $repo->get('role.coordinator');

        self::assertNotNull($loaded);
        self::assertSame($file->ref(), $loaded->ref());
        self::assertSame($file->uuid, $loaded->uuid);
        self::assertSame($file->fields, $loaded->fields);
    }

    #[Test]
    public function hasReflectsFilesystemPresence(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        self::assertFalse($repo->has('role.coordinator'));

        $repo->put($this->makeFile());

        self::assertTrue($repo->has('role.coordinator'));
    }

    #[Test]
    public function deleteRemovesFile(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile());

        $repo->delete('role.coordinator');

        self::assertFalse($repo->has('role.coordinator'));
        self::assertFileDoesNotExist($this->tempDir . '/role.coordinator.yml');
    }

    #[Test]
    public function deleteIsNoOpWhenAbsent(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);

        // Must not throw.
        $repo->delete('role.coordinator');

        self::assertFalse($repo->has('role.coordinator'));
    }

    #[Test]
    public function listYieldsAllValidEntries(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin'));
        $repo->put($this->makeFile('role', 'coordinator'));
        $repo->put($this->makeFile('taxonomy_vocabulary', 'community_categories'));

        $refs = [];
        foreach ($repo->list() as $entry) {
            $refs[] = $entry->ref();
        }

        sort($refs, \SORT_STRING);
        self::assertSame(
            ['role.admin', 'role.coordinator', 'taxonomy_vocabulary.community_categories'],
            $refs,
        );
    }

    #[Test]
    public function listSkipsNonConformingFiles(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin'));

        // Drop a non-conforming sibling file into the directory.
        file_put_contents($this->tempDir . '/README.md', "scratch\n");
        file_put_contents($this->tempDir . '/UPPERCASE.NotValid.yml', "_meta: {}\n");

        $refs = [];
        foreach ($repo->list() as $entry) {
            $refs[] = $entry->ref();
        }

        self::assertSame(['role.admin'], $refs);
    }

    #[Test]
    public function getRejectsMalformedRef(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $this->expectException(\InvalidArgumentException::class);
        $repo->get('Invalid.Ref');
    }

    #[Test]
    public function deleteRejectsMalformedRef(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $this->expectException(\InvalidArgumentException::class);
        $repo->delete('Invalid-Ref');
    }

    #[Test]
    public function putCreatesMissingDirectory(): void
    {
        $nested = $this->tempDir . '/nested/dir';
        $repo = new ConfigSyncRepository($nested);
        $repo->put($this->makeFile());

        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/role.coordinator.yml');
    }

    #[Test]
    public function putRefusesToReplaceASymbolicLink(): void
    {
        $outside = $this->tempDir . '/outside.yml';
        file_put_contents($outside, "outside\n");
        self::assertTrue(symlink($outside, $this->tempDir . '/role.coordinator.yml'));

        $repo = new ConfigSyncRepository($this->tempDir);
        try {
            $repo->put($this->makeFile());
            self::fail('A symbolic-link sync member was replaced.');
        } catch (\Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException $exception) {
            self::assertStringContainsString('regular non-link file', $exception->getMessage());
        }

        self::assertSame("outside\n", file_get_contents($outside));
        self::assertSame([], glob($this->tempDir . '/role.coordinator.yml.tmp-*') ?: []);
    }

    #[Test]
    public function manifestExposesContentHashAndPath(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $file = $this->makeFile();
        $repo->put($file);

        $manifest = $repo->manifest();

        self::assertCount(1, $manifest);
        self::assertSame($file->ref(), $manifest[0]->ref);
        self::assertSame($file->contentHash(), $manifest[0]->contentHash);
        self::assertSame($this->tempDir . '/role.coordinator.yml', $manifest[0]->path);
        self::assertGreaterThan(0, $manifest[0]->mtime);
    }

    #[Test]
    public function rejectsEmptySyncPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfigSyncRepository('');
    }

    private function makeFile(string $entityType = 'role', string $entityId = 'coordinator'): ConfigSyncFile
    {
        return new ConfigSyncFile(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: [
                'id' => $entityId,
                'label' => ucfirst($entityId),
            ],
        );
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
