<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigExportFileResult;
use Waaseyaa\Config\Sync\ConfigExportResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigExporter::class)]
#[CoversClass(ConfigExportFileResult::class)]
#[CoversClass(ConfigExportResult::class)]
final class ConfigExporterTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_export_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function empty_source_produces_zero_outcomes(): void
    {
        $exporter = new ConfigExporter(
            source: $this->source([]),
            repository: new ConfigSyncRepository($this->tempDir),
        );

        $result = $exporter->export();

        self::assertSame([], $result->files);
        self::assertSame(0, $result->created());
        self::assertSame(0, $result->updated());
        self::assertSame(0, $result->unchanged());
        self::assertSame('0 created, 0 updated, 0 unchanged.', $result->summary());
    }

    #[Test]
    public function brand_new_files_count_as_created_and_are_written(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $exporter = new ConfigExporter(
            source: $this->source([
                $this->makeFile('role', 'admin', ['label' => 'Admin']),
                $this->makeFile('role', 'member', ['label' => 'Member']),
            ]),
            repository: $repository,
        );

        $result = $exporter->export();

        self::assertSame(2, $result->created());
        self::assertSame(0, $result->updated());
        self::assertSame(0, $result->unchanged());
        self::assertSame('2 created, 0 updated, 0 unchanged.', $result->summary());
        self::assertFileExists($this->tempDir . '/role.admin.yml');
        self::assertFileExists($this->tempDir . '/role.member.yml');
        self::assertSame(ConfigExportFileResult::STATUS_CREATED, $result->files[0]->status);
        self::assertSame('role.admin.yml', $result->files[0]->filename);
        self::assertSame('role.admin', $result->files[0]->ref);
    }

    #[Test]
    public function identical_existing_file_counts_as_unchanged(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $original = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repository->put($original);

        $exporter = new ConfigExporter(
            source: $this->source([$original]),
            repository: $repository,
        );

        $result = $exporter->export();

        self::assertSame(0, $result->created());
        self::assertSame(0, $result->updated());
        self::assertSame(1, $result->unchanged());
        self::assertSame(ConfigExportFileResult::STATUS_UNCHANGED, $result->files[0]->status);
    }

    #[Test]
    public function diff_flag_preserves_mtime_of_unchanged_files(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $original = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repository->put($original);

        $target = $this->tempDir . '/role.admin.yml';
        // Backdate the file's mtime so we can detect a rewrite.
        touch($target, time() - 1000);
        $originalMtime = filemtime($target);
        self::assertIsInt($originalMtime);

        $exporter = new ConfigExporter(
            source: $this->source([$original]),
            repository: $repository,
        );

        $exporter->export(diff: true);

        clearstatcache(true, $target);
        self::assertSame($originalMtime, filemtime($target));
    }

    #[Test]
    public function default_mode_rewrites_unchanged_files_to_refresh_mtime(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $original = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repository->put($original);

        $target = $this->tempDir . '/role.admin.yml';
        touch($target, time() - 1000);
        $originalMtime = filemtime($target);
        self::assertIsInt($originalMtime);

        $exporter = new ConfigExporter(
            source: $this->source([$original]),
            repository: $repository,
        );

        $exporter->export();

        clearstatcache(true, $target);
        // mtime should advance because the file was rewritten via temp+rename.
        self::assertGreaterThan($originalMtime, filemtime($target));
    }

    #[Test]
    public function changed_content_counts_as_updated_and_is_written(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $repository->put($this->makeFile('role', 'admin', ['label' => 'Admin']));

        $changed = $this->makeFile('role', 'admin', ['label' => 'Administrator']);
        $exporter = new ConfigExporter(
            source: $this->source([$changed]),
            repository: $repository,
        );

        $result = $exporter->export(diff: true);

        self::assertSame(0, $result->created());
        self::assertSame(1, $result->updated());
        self::assertSame(0, $result->unchanged());
        self::assertSame(ConfigExportFileResult::STATUS_UPDATED, $result->files[0]->status);

        $persisted = $repository->get('role.admin');
        self::assertNotNull($persisted);
        self::assertSame('Administrator', $persisted->fields['label']);
    }

    #[Test]
    public function dry_run_does_not_create_files(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $exporter = new ConfigExporter(
            source: $this->source([
                $this->makeFile('role', 'admin', ['label' => 'Admin']),
            ]),
            repository: $repository,
        );

        $result = $exporter->export(dryRun: true);

        self::assertSame(1, $result->created());
        self::assertTrue($result->dryRun);
        self::assertFileDoesNotExist($this->tempDir . '/role.admin.yml');
    }

    #[Test]
    public function dry_run_does_not_modify_existing_files(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $original = $this->makeFile('role', 'admin', ['label' => 'Admin']);
        $repository->put($original);

        $changed = $this->makeFile('role', 'admin', ['label' => 'Administrator']);
        $exporter = new ConfigExporter(
            source: $this->source([$changed]),
            repository: $repository,
        );

        $result = $exporter->export(diff: true, dryRun: true);

        self::assertSame(1, $result->updated());
        self::assertTrue($result->dryRun);

        // Disk content must still be the original.
        $persisted = $repository->get('role.admin');
        self::assertNotNull($persisted);
        self::assertSame('Admin', $persisted->fields['label']);
    }

    #[Test]
    public function mixed_outcomes_produce_canonical_summary(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $repository->put($this->makeFile('role', 'admin', ['label' => 'Admin']));
        $repository->put($this->makeFile('role', 'member', ['label' => 'Member']));

        $source = $this->source([
            // Brand-new -> created.
            $this->makeFile('role', 'coordinator', ['label' => 'Coordinator']),
            // Same content as existing -> unchanged.
            $this->makeFile('role', 'admin', ['label' => 'Admin']),
            // Changed content -> updated.
            $this->makeFile('role', 'member', ['label' => 'Member Updated']),
        ]);
        $exporter = new ConfigExporter(source: $source, repository: $repository);

        $result = $exporter->export(diff: true);

        self::assertSame('1 created, 1 updated, 1 unchanged.', $result->summary());
        // Outcomes preserve source-iteration order.
        self::assertSame(
            [
                ConfigExportFileResult::STATUS_CREATED,
                ConfigExportFileResult::STATUS_UNCHANGED,
                ConfigExportFileResult::STATUS_UPDATED,
            ],
            array_map(static fn(ConfigExportFileResult $r) => $r->status, $result->files),
        );
    }

    #[Test]
    public function serialization_failures_propagate_to_caller(): void
    {
        // A pathological source that throws from inside iterate(). The
        // exporter must not swallow it — the CLI command catches the
        // exception and maps it to exit code 1 (FR-021).
        $source = new class implements ConfigSyncFileSourceInterface {
            public function iterate(): iterable
            {
                yield from [];
                throw ConfigSerializationException::typeMismatch('label', 'string', 'array');
            }
        };

        $exporter = new ConfigExporter(
            source: $source,
            repository: new ConfigSyncRepository($this->tempDir),
        );

        $this->expectException(ConfigSerializationException::class);
        $exporter->export();
    }

    #[Test]
    public function result_summary_format_is_stable(): void
    {
        // Pin the FR-020 wire format so a downstream consumer (CI grep,
        // changelog drift detector) can rely on it.
        $result = new ConfigExportResult(files: [
            new ConfigExportFileResult('role.admin', 'role.admin.yml', ConfigExportFileResult::STATUS_CREATED),
            new ConfigExportFileResult('role.member', 'role.member.yml', ConfigExportFileResult::STATUS_UPDATED),
            new ConfigExportFileResult('role.guest', 'role.guest.yml', ConfigExportFileResult::STATUS_UNCHANGED),
            new ConfigExportFileResult('role.coord', 'role.coord.yml', ConfigExportFileResult::STATUS_CREATED),
        ], dryRun: false);

        self::assertSame('2 created, 1 updated, 1 unchanged.', $result->summary());
        self::assertSame(2, $result->created());
        self::assertSame(1, $result->updated());
        self::assertSame(1, $result->unchanged());
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

}
