<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;

#[CoversNothing]
final class FrameworkRuntimeTableCatalogueCompletenessTest extends TestCase
{
    /**
     * Migration-installed tables that predate catalogue adjudication.
     *
     * Every entry is owned by its originating change unit: remove an entry
     * once that unit either catalogues the table in
     * FrameworkRuntimeTableCatalogue or documents it as an
     * application-declared artifact table. Never add an entry for a new
     * migration-created table — new serving runtime state must be classified
     * in the framework catalogue, otherwise SqliteArtifactPreparer fails
     * closed on a legitimate serving database during artifact installation.
     */
    private const array PRE_CATALOGUE_ADJUDICATION_TABLES = [
        'cache_items',
        'classification_label_definition',
        'media_version',
        'publishing_idempotency',
        'retention_policy',
        'state',
        'waaseyaa_config_activation',
        'waaseyaa_config_activation_counter',
        'waaseyaa_config_activation_manifest',
        'waaseyaa_config_activation_v2',
        'waaseyaa_config_candidate',
        'waaseyaa_config_candidate_sweep_fence',
        'waaseyaa_config_entry',
        'waaseyaa_config_entry_contract',
        'waaseyaa_config_entry_v2',
        'waaseyaa_config_generation',
        'waaseyaa_config_generation_v2',
        'waaseyaa_config_manifest_replay',
        'waaseyaa_entity_mutation_authority',
        'waaseyaa_scheduler_effect_fences',
        'waaseyaa_scheduler_fence_sequence',
        'waaseyaa_scheduler_leases',
        'waaseyaa_scheduler_occurrence_outbox',
        'waaseyaa_scheduler_occurrences',
        'waaseyaa_schema_authority',
    ];

    #[Test]
    public function migration_installed_runtime_tables_cannot_escape_catalogue_classification(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [];
        foreach (glob($root . '/packages/*/composer.json') ?: [] as $composerPath) {
            $composer = json_decode(
                (string) file_get_contents($composerPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $migrationsDir = $composer['extra']['waaseyaa']['migrations'] ?? null;
            if (!is_string($migrationsDir)) {
                continue;
            }
            $paths[(string) $composer['name']] = dirname($composerPath) . '/' . $migrationsDir;
        }
        ksort($paths, SORT_STRING);
        self::assertNotSame([], $paths, 'No package declares extra.waaseyaa.migrations.');

        $all = new MigrationLoader($root, new PackageManifest(migrations: $paths))->loadAll();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migrator = new Migrator($connection, new MigrationRepository($connection));
        self::assertGreaterThan(0, $migrator->run($all)->count);

        $installed = array_map('strval', $connection->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        ));
        $catalogued = array_keys(new FrameworkRuntimeTableCatalogue()->definitions());

        $unclaimed = array_values(array_diff($installed, $catalogued));
        sort($unclaimed, SORT_STRING);
        $adjudicated = self::PRE_CATALOGUE_ADJUDICATION_TABLES;
        sort($adjudicated, SORT_STRING);

        self::assertSame(
            [],
            array_values(array_diff($unclaimed, $adjudicated)),
            'Migration-installed tables escaped runtime-table catalogue classification. '
            . 'Classify each table in FrameworkRuntimeTableCatalogue (SqliteArtifactPreparer '
            . 'rejects serving databases containing tables that are neither catalogued nor '
            . 'application-declared).',
        );
        self::assertSame(
            [],
            array_values(array_diff($adjudicated, $unclaimed)),
            'Stale pre-catalogue adjudication entries: the table is now catalogued or no '
            . 'longer migration-installed; remove it from PRE_CATALOGUE_ADJUDICATION_TABLES.',
        );
    }
}
