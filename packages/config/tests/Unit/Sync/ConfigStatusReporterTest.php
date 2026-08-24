<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\DiffResult;
use Waaseyaa\Config\Sync\StatusEntry;
use Waaseyaa\Config\Sync\StatusReport;

#[CoversClass(ConfigStatusReporter::class)]
#[CoversClass(StatusReport::class)]
#[CoversClass(StatusEntry::class)]
final class ConfigStatusReporterTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_status_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function counts_aggregate_each_diff_status(): void
    {
        // Sync side: admin (matches), member (drift), only_in_sync
        // Active side: admin (matches), member (different fields), only_in_active
        $admin = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $memberSync = $this->makeFile('role', 'member', ['label' => 'Member sync']);
        $memberActive = $this->makeFile('role', 'member', ['label' => 'Member active']);
        $onlyInSync = $this->makeFile('role', 'only_in_sync', []);
        $onlyInActive = $this->makeFile('role', 'only_in_active', []);

        $repo = $this->seedRepo([$admin, $memberSync, $onlyInSync]);
        $reporter = new ConfigStatusReporter(new ConfigDiffer(
            $repo,
            $this->source([$admin, $memberActive, $onlyInActive]),
        ));

        $report = $reporter->status();

        self::assertSame([
            'in_sync' => 1,
            'drift' => 1,
            'sync_only' => 1,
            'active_only' => 1,
            'renamed' => 0,
        ], $report->counts());
        self::assertSame(4, $report->total());
        self::assertTrue($report->hasDifferences());
    }

    #[Test]
    public function empty_stores_yield_zero_counts_and_no_differences(): void
    {
        $repo = $this->seedRepo([]);
        $reporter = new ConfigStatusReporter(new ConfigDiffer($repo, $this->source([])));

        $report = $reporter->status();

        self::assertSame(0, $report->total());
        self::assertFalse($report->hasDifferences());
        self::assertSame([
            'in_sync' => 0,
            'drift' => 0,
            'sync_only' => 0,
            'active_only' => 0,
            'renamed' => 0,
        ], $report->counts());
    }

    #[Test]
    public function entries_are_sorted_alphabetically_by_ref(): void
    {
        $files = [
            $this->makeFile('role', 'zebra', []),
            $this->makeFile('role', 'admin', []),
            $this->makeFile('role', 'member', []),
        ];
        $repo = $this->seedRepo($files);
        $reporter = new ConfigStatusReporter(new ConfigDiffer($repo, $this->source($files)));

        $report = $reporter->status();

        $refs = array_map(static fn(StatusEntry $e): string => $e->ref, $report->entries);
        self::assertSame(['role.admin', 'role.member', 'role.zebra'], $refs);
    }

    #[Test]
    public function rename_is_propagated_into_status_entry(): void
    {
        $uuid = ConfigSyncFile::deterministicUuid('role', 'coordinator');
        $sync = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'community_coordinator',
            uuid: $uuid,
            dependencies: [],
            langcode: 'en',
            fields: ['label' => 'CC'],
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
            fields: ['label' => 'C'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        $repo = $this->seedRepo([$sync]);
        $reporter = new ConfigStatusReporter(new ConfigDiffer($repo, $this->source([$active])));

        $report = $reporter->status();

        self::assertSame(1, $report->total());
        self::assertSame(DiffResult::STATUS_RENAMED, $report->entries[0]->status);
        self::assertSame('role.coordinator', $report->entries[0]->renamedFrom);
    }

    #[Test]
    public function should_render_per_entity_table_uses_threshold_of_fifty(): void
    {
        self::assertSame(50, StatusReport::PER_ENTITY_TABLE_THRESHOLD);

        $entriesUnder = [];
        for ($i = 0; $i < 49; ++$i) {
            $entriesUnder[] = new StatusEntry(
                ref: sprintf('role.entry_%02d', $i),
                status: DiffResult::STATUS_IN_SYNC,
            );
        }
        $reportUnder = new StatusReport(entries: $entriesUnder);
        self::assertTrue($reportUnder->shouldRenderPerEntityTable());

        $entriesAt = [];
        for ($i = 0; $i < 50; ++$i) {
            $entriesAt[] = new StatusEntry(
                ref: sprintf('role.entry_%02d', $i),
                status: DiffResult::STATUS_IN_SYNC,
            );
        }
        $reportAt = new StatusReport(entries: $entriesAt);
        self::assertFalse($reportAt->shouldRenderPerEntityTable());
    }

    #[Test]
    public function status_entry_rejects_invalid_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StatusEntry(ref: 'role.admin', status: 'gibberish');
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
