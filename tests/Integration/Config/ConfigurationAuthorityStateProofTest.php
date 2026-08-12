<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityResolver;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseActiveConfigurationBridge;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationGenerationResolver;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class ConfigurationAuthorityStateProofTest extends TestCase
{
    private string $root;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_cfg01_state_' . bin2hex(random_bytes(6));
        $this->databasePath = $this->root . '/storage/waaseyaa.sqlite';
        self::assertTrue(mkdir($this->root . '/config', 0o700, true));
        self::assertTrue(mkdir($this->root . '/storage/config-sync', 0o700, true));
        self::assertTrue(mkdir($this->root . '/storage/framework', 0o700, true));
        file_put_contents($this->root . '/config/waaseyaa.php', "<?php return ['environment' => 'production'];\n");
        file_put_contents($this->root . '/storage/framework/config.php', "<?php return ['derived' => true];\n");
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function status_diff_and_validate_are_byte_read_only_across_every_configuration_domain(): void
    {
        [$database, $bridge, $repository] = $this->seedAuthority();
        $differ = new ConfigDiffer($repository, $bridge);

        // Warm SQLite's read-side support files before the byte proof begins.
        self::assertSame(['system.site'], $bridge->activeStorage()->listAll());
        $before = $this->snapshot();

        self::assertCount(1, $differ->diffAll());
        self::assertCount(1, new ConfigStatusReporter($differ)->status()->entries);
        self::assertTrue(new ConfigSyncValidator($repository)->validate()->isValid());

        self::assertSame($before, $this->snapshot());
        $database->getConnection()->close();
    }

    #[Test]
    public function dry_run_executes_preflight_and_diff_inputs_without_any_domain_mutation(): void
    {
        [$database, $bridge, $repository] = $this->seedAuthority();
        $preflight = new RecordingConfigPreflight();
        $hook = new RecordingConfigApplyHook();
        $importer = new ConfigImporter($repository, $hook, $preflight);

        self::assertSame(['system.site'], $bridge->activeStorage()->listAll());
        $before = $this->snapshot();
        $result = $importer->import(
            dryRun: true,
            deleteOrphans: true,
            activeRefs: ['system.site', 'system.legacy'],
        );

        self::assertTrue($result->dryRun);
        self::assertSame(1, $preflight->calls);
        self::assertTrue($preflight->lastDryRun);
        self::assertTrue($preflight->lastDeleteOrphans);
        self::assertSame(0, $hook->applyCalls);
        self::assertSame(0, $hook->deleteCalls);
        self::assertSame($before, $this->snapshot());
        $database->getConnection()->close();
    }

    /** @return array{DBALDatabase, DatabaseActiveConfigurationBridge, ConfigSyncRepository} */
    private function seedAuthority(): array
    {
        $database = DBALDatabase::createSqlite($this->databasePath, 'testing');
        $migration = require dirname(__DIR__, 3) . '/packages/entity-storage/migrations/2026_08_12_000002_configuration_authority.php';
        $migration->up(new SchemaBuilder($database->getConnection()));

        $base = new ConfigurationAuthorityResolver()->resolve(
            $this->root,
            $database->databaseIdentity(),
            ['config' => ['sync_path' => 'storage/config-sync']],
            [],
        );
        $generation = str_repeat('b', 64);
        $file = new ConfigSyncFile(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['name' => 'Waaseyaa'],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_generation '
            . '(authority_id, generation_id, activation_sequence, schema_version, manifest_hash, lifecycle_state, created_at) '
            . 'VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$base->authorityId, $generation, 'config-schema.v1', str_repeat('c', 64), 'active', '2026-08-12T00:00:00Z'],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_entry '
            . '(authority_id, generation_id, config_name, entity_type, entity_id, uuid, dependencies_json, langcode, fields_json, content_hash) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $base->authorityId,
                $generation,
                $file->ref(),
                $file->entityType,
                $file->entityId,
                $file->uuid,
                '[]',
                $file->langcode,
                json_encode($file->fields, JSON_THROW_ON_ERROR),
                $file->contentHash(),
            ],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_activation (authority_id, generation_id, activation_sequence, activated_at) VALUES (?, ?, 1, ?)',
            [$base->authorityId, $generation, '2026-08-12T00:00:00Z'],
        );
        $context = new DatabaseConfigurationGenerationResolver($database)->bind($base);
        $bridge = new DatabaseActiveConfigurationBridge($database, $context);
        $repository = new ConfigSyncRepository($context->syncPath);
        $repository->put($file);

        return [$database, $bridge, $repository];
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $this->root)) + 1);
                $snapshot[$relative] = hash_file('sha256', $item->getPathname());
            }
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
final class RecordingConfigPreflight implements ConfigImportPreflightInterface
{
    public int $calls = 0;
    public bool $lastDryRun = false;
    public bool $lastDeleteOrphans = false;

    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): void {
        ++$this->calls;
        $this->lastDryRun = $dryRun;
        $this->lastDeleteOrphans = $deleteOrphans;
    }
}

final class RecordingConfigApplyHook implements ConfigImportApplyHookInterface
{
    public int $applyCalls = 0;
    public int $deleteCalls = 0;

    public function apply(ConfigSyncFile $file): string
    {
        ++$this->applyCalls;

        return 'updated';
    }

    public function delete(string $ref): void
    {
        ++$this->deleteCalls;
    }
}
