<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase28;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigExportFileResult;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\DiffResult;
use Waaseyaa\Config\Tests\Fixtures\MinooRoundTripFixture;

/**
 * Minoo round-trip integration (FR-054, FR-055).
 *
 * Exercises the full
 * {@see ConfigExporter} → on-disk mutation → {@see ConfigImporter} → {@see ConfigDiffer}
 * loop end-to-end against the realistic
 * {@see MinooRoundTripFixture} (roles + vocabulary + menu).
 *
 *  - FR-054: export → modify a sync file → import → diff is empty.
 *  - FR-055: export → unchanged round trip → no observable active-store
 *    change, no spurious diffs.
 *
 * The active store is modelled by an in-memory map mutated by the apply hook;
 * the sync store is a real {@see ConfigSyncRepository} backed by a temp
 * directory. Together they let the test assert the full operator-visible
 * contract without booting a kernel.
 */
#[CoversNothing]
final class ConfigSyncRoundTripIntegrationTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_roundtrip_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function export_then_import_then_diff_is_empty_after_modifying_one_sync_file(): void
    {
        // 1. Seed an active store mirroring the canonical fixture.
        $activeStore = ActiveStoreDouble::fromFiles(MinooRoundTripFixture::files());
        $repository = new ConfigSyncRepository($this->tempDir);

        // 2. Export active → sync.
        $exporter = new ConfigExporter(
            source: $activeStore,
            repository: $repository,
        );
        $exportResult = $exporter->export();
        self::assertSame(4, $exportResult->created());

        // 3. Mutate one sync file in place: rename role.admin's label.
        $repository->put(ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: ConfigSyncFile::deterministicUuid('role', 'admin'),
            dependencies: [],
            langcode: 'en',
            fields: [
                'label' => 'Administrator',
                'permissions' => ['administer site', 'edit any node'],
            ],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        ));

        // 4. Pre-flight diff: sync now drifts from active for role.admin only.
        $preDiff = new ConfigDiffer(
            syncRepository: $repository,
            activeSource: $activeStore,
        );
        $driftStatuses = self::statusByRef($preDiff->diffAll());
        self::assertSame(DiffResult::STATUS_DRIFT, $driftStatuses['role.admin']);
        self::assertSame(DiffResult::STATUS_IN_SYNC, $driftStatuses['role.member']);

        // 5. Import sync → active. role.admin should be updated.
        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $activeStore,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );
        $importResult = $importer->import(activeRefs: $activeStore->refs());
        self::assertSame(0, $importResult->failureCount());

        // 6. Post-import diff must classify *every* ref as in_sync — FR-054.
        $postDiff = new ConfigDiffer(
            syncRepository: $repository,
            activeSource: $activeStore,
        );
        foreach ($postDiff->diffAll() as $result) {
            self::assertSame(
                DiffResult::STATUS_IN_SYNC,
                $result->status,
                sprintf('Ref %s must be in_sync after round trip; got %s.', $result->ref, $result->status),
            );
            self::assertSame('', $result->diff);
        }

        // 7. The active store now reflects the renamed label.
        $admin = $activeStore->get('role.admin');
        self::assertNotNull($admin);
        self::assertSame('Administrator', $admin->fields['label']);
    }

    #[Test]
    public function unchanged_round_trip_does_not_touch_active_store_or_emit_diffs(): void
    {
        // FR-055: a redundant export → import → diff cycle is a no-op.
        $activeStore = ActiveStoreDouble::fromFiles(MinooRoundTripFixture::files());
        $repository = new ConfigSyncRepository($this->tempDir);

        // First pass: seed sync from active.
        new ConfigExporter(
            source: $activeStore,
            repository: $repository,
        )->export();
        $activeStore->resetMutationLog();

        // Second pass: re-export (every file is now unchanged on disk).
        $exportResult = new ConfigExporter(
            source: $activeStore,
            repository: $repository,
        )->export(diff: true);
        self::assertSame(0, $exportResult->created());
        self::assertSame(0, $exportResult->updated());
        self::assertSame(4, $exportResult->unchanged());
        foreach ($exportResult->files as $file) {
            self::assertSame(ConfigExportFileResult::STATUS_UNCHANGED, $file->status);
        }

        // Importing a sync store that matches the active store byte-for-byte
        // calls apply() for each ref (the importer does not pre-filter on
        // hash; that's the hook's job) but produces no functional change.
        $importer = new ConfigImporter(
            repository: $repository,
            applyHook: $activeStore,
            preflight: new \Waaseyaa\Config\Testing\AllowingConfigImportPreflight(),
        );
        $importer->import(activeRefs: $activeStore->refs());

        // No spurious diffs anywhere.
        $diffs = new ConfigDiffer(
            syncRepository: $repository,
            activeSource: $activeStore,
        )->diffAll();
        foreach ($diffs as $result) {
            self::assertSame(DiffResult::STATUS_IN_SYNC, $result->status);
        }

        // Mutation hashes must match the pre-roundtrip snapshot: the active
        // store's observable shape did not change.
        $expected = self::hashFixture(MinooRoundTripFixture::files());
        $actual = self::hashFixture($activeStore->snapshot());
        self::assertSame($expected, $actual, 'Active store must be byte-identical after unchanged round trip.');
    }

    /**
     * @param list<DiffResult> $results
     *
     * @return array<string, string>
     */
    private static function statusByRef(array $results): array
    {
        $byRef = [];
        foreach ($results as $result) {
            $byRef[$result->ref] = $result->status;
        }

        return $byRef;
    }

    /**
     * @param array<string, ConfigSyncFile> $files
     */
    private static function hashFixture(array $files): string
    {
        ksort($files, \SORT_STRING);
        $hashes = [];
        foreach ($files as $ref => $file) {
            $hashes[$ref] = $file->contentHash();
        }

        return hash('sha256', json_encode($hashes, \JSON_THROW_ON_ERROR));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}

/**
 * Test-only in-memory active store: doubles as
 * {@see ConfigSyncFileSourceInterface} (so the exporter can read it) and
 * {@see ConfigImportApplyHookInterface} (so the importer can mutate it).
 *
 * Keeping this private to the integration suite keeps Layer 1
 * (`packages/config/src/`) free of test plumbing while still letting the
 * fixture round-trip mirror the production contract.
 *
 * @internal
 */
final class ActiveStoreDouble implements ConfigSyncFileSourceInterface, ConfigImportApplyHookInterface
{
    /** @var array<string, ConfigSyncFile> */
    private array $byRef;

    /** @var list<array{op: string, ref: string}> */
    public array $mutations = [];

    /**
     * @param array<string, ConfigSyncFile> $byRef
     */
    private function __construct(array $byRef)
    {
        ksort($byRef, \SORT_STRING);
        $this->byRef = $byRef;
    }

    /**
     * @param array<string, ConfigSyncFile> $files
     */
    public static function fromFiles(array $files): self
    {
        return new self($files);
    }

    public function iterate(): iterable
    {
        foreach ($this->byRef as $file) {
            yield $file;
        }
    }

    public function get(string $ref): ?ConfigSyncFile
    {
        return $this->byRef[$ref] ?? null;
    }

    /**
     * @return list<string>
     */
    public function refs(): array
    {
        return array_keys($this->byRef);
    }

    /**
     * @return array<string, ConfigSyncFile>
     */
    public function snapshot(): array
    {
        return $this->byRef;
    }

    public function resetMutationLog(): void
    {
        $this->mutations = [];
    }

    public function apply(ConfigSyncFile $file): string
    {
        $existing = $this->byRef[$file->ref()] ?? null;
        if ($existing === null) {
            $this->byRef[$file->ref()] = $file;
            ksort($this->byRef, \SORT_STRING);
            $this->mutations[] = ['op' => 'create', 'ref' => $file->ref()];

            return ConfigImportEntryResult::STATUS_CREATED;
        }

        if ($existing->contentHash() === $file->contentHash()) {
            $this->mutations[] = ['op' => 'noop', 'ref' => $file->ref()];

            return ConfigImportEntryResult::STATUS_UNCHANGED;
        }

        $this->byRef[$file->ref()] = $file;
        $this->mutations[] = ['op' => 'update', 'ref' => $file->ref()];

        return ConfigImportEntryResult::STATUS_UPDATED;
    }

    public function delete(string $ref): void
    {
        unset($this->byRef[$ref]);
        $this->mutations[] = ['op' => 'delete', 'ref' => $ref];
    }
}
