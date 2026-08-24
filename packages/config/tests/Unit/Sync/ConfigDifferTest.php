<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\DiffResult;

#[CoversClass(ConfigDiffer::class)]
#[CoversClass(DiffResult::class)]
final class ConfigDifferTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_differ_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function identical_sides_yield_in_sync(): void
    {
        $file = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repo = $this->seedRepo([$file]);
        $differ = new ConfigDiffer($repo, $this->source([$file]));

        $results = $differ->diffAll();

        self::assertCount(1, $results);
        self::assertSame(DiffResult::STATUS_IN_SYNC, $results[0]->status);
        self::assertSame('', $results[0]->diff);
        self::assertFalse($results[0]->hasDifferences());
    }

    #[Test]
    public function drift_renders_unified_diff_of_serialized_yaml(): void
    {
        $sync = $this->makeFile('role', 'admin', ['label' => 'Admin (sync)']);
        $active = $this->makeFile('role', 'admin', ['label' => 'Admin (active)']);
        $repo = $this->seedRepo([$sync]);
        $differ = new ConfigDiffer($repo, $this->source([$active]));

        $results = $differ->diffAll();

        self::assertCount(1, $results);
        self::assertSame(DiffResult::STATUS_DRIFT, $results[0]->status);
        self::assertStringContainsString('--- a/role.admin', $results[0]->diff);
        self::assertStringContainsString('+++ b/role.admin', $results[0]->diff);
        self::assertStringContainsString('-', $results[0]->diff);
        self::assertStringContainsString('+', $results[0]->diff);
    }

    #[Test]
    public function sync_only_emits_status_and_addition_diff(): void
    {
        $sync = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repo = $this->seedRepo([$sync]);
        $differ = new ConfigDiffer($repo, $this->source([]));

        $results = $differ->diffAll();

        self::assertCount(1, $results);
        self::assertSame(DiffResult::STATUS_SYNC_ONLY, $results[0]->status);
        self::assertStringContainsString('--- /dev/null', $results[0]->diff);
        self::assertStringContainsString('+++ b/role.admin', $results[0]->diff);
    }

    #[Test]
    public function active_only_emits_status_and_deletion_diff(): void
    {
        $active = $this->makeFile('role', 'orphan', ['label' => 'Orphan']);
        $repo = $this->seedRepo([]);
        $differ = new ConfigDiffer($repo, $this->source([$active]));

        $results = $differ->diffAll();

        self::assertCount(1, $results);
        self::assertSame(DiffResult::STATUS_ACTIVE_ONLY, $results[0]->status);
        self::assertStringContainsString('--- a/role.orphan', $results[0]->diff);
        self::assertStringContainsString('+++ /dev/null', $results[0]->diff);
    }

    #[Test]
    public function uuid_match_with_different_ref_is_renamed_not_create_and_delete(): void
    {
        // Sync wants the entity named role.community_coordinator.
        // Active currently has it named role.coordinator but with the same uuid.
        $uuid = ConfigSyncFile::deterministicUuid('role', 'coordinator');
        $sync = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'community_coordinator',
            uuid: $uuid,
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Community Coordinator'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        $active = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'coordinator',
            uuid: $uuid,
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'Coordinator'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        $repo = $this->seedRepo([$sync]);
        $differ = new ConfigDiffer($repo, $this->source([$active]));

        $results = $differ->diffAll();

        self::assertCount(1, $results, 'rename collapses sync_only + active_only into one entry');
        self::assertSame(DiffResult::STATUS_RENAMED, $results[0]->status);
        self::assertSame('role.community_coordinator', $results[0]->ref);
        self::assertSame('role.coordinator', $results[0]->renamedFrom);
        self::assertSame($uuid, $results[0]->uuid);
    }

    #[Test]
    public function scoped_diff_returns_single_result(): void
    {
        $sync = $this->makeFile('role', 'admin', ['label' => 'Admin (sync)']);
        $active = $this->makeFile('role', 'admin', ['label' => 'Admin (active)']);
        $repo = $this->seedRepo([$sync]);
        $differ = new ConfigDiffer($repo, $this->source([$active]));

        $result = $differ->diff('role.admin');

        self::assertNotNull($result);
        self::assertSame(DiffResult::STATUS_DRIFT, $result->status);
    }

    #[Test]
    public function scoped_diff_returns_null_for_unknown_ref(): void
    {
        $repo = $this->seedRepo([]);
        $differ = new ConfigDiffer($repo, $this->source([]));

        self::assertNull($differ->diff('role.nonexistent'));
    }

    #[Test]
    public function results_are_sorted_alphabetically_by_ref(): void
    {
        $files = [
            $this->makeFile('role', 'zebra', []),
            $this->makeFile('role', 'admin', []),
            $this->makeFile('role', 'member', []),
        ];
        $repo = $this->seedRepo($files);
        $differ = new ConfigDiffer($repo, $this->source($files));

        $results = $differ->diffAll();

        $refs = array_map(static fn(DiffResult $r): string => $r->ref, $results);
        self::assertSame(['role.admin', 'role.member', 'role.zebra'], $refs);
    }

    #[Test]
    public function diff_result_constructor_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiffResult(ref: 'role.admin', status: 'gibberish');
    }

    #[Test]
    public function renamed_status_requires_renamed_from(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiffResult(ref: 'role.admin', status: DiffResult::STATUS_RENAMED);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeFile(string $entityType, string $entityId, array $fields): ConfigSyncFile
    {
        ksort($fields, \SORT_STRING);

        return ConfigSyncFile::writable(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

    /**
     * @param list<ConfigSyncFile> $files
     */
    private function seedRepo(array $files): ConfigSyncRepository
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        foreach ($files as $file) {
            $repo->put($file);
        }

        return $repo;
    }

    /**
     * @param list<ConfigSyncFile> $files
     */
    private function source(array $files): ConfigSyncFileSourceInterface
    {
        return new class ($files) implements ConfigSyncFileSourceInterface {
            /** @param list<ConfigSyncFile> $files */
            public function __construct(private readonly array $files) {}

            public function iterate(): iterable
            {
                yield from $this->files;
            }
        };
    }

}
