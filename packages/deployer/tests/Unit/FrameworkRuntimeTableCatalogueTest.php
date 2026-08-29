<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Deployer\RuntimeState\RuntimeTablePolicy;

/**
 * The catalogue's own invariants, and the #2547 classifications pinned by name.
 *
 * A policy here decides whether a serving host's rows survive a data refresh or
 * are replaced by the build's, so changing one is a data-handling decision. The
 * table below exists so that such a change has to be made on purpose, in a diff
 * that says which row it is changing and why.
 */
#[CoversClass(FrameworkRuntimeTableCatalogue::class)]
final class FrameworkRuntimeTableCatalogueTest extends TestCase
{
    /**
     * The 22 tables #2547 reported as unclassified, with the policy each
     * received. Every entry is a decision recorded in the catalogue's own
     * comments; this is the executable half.
     *
     * @var array<string, RuntimeTablePolicy>
     */
    private const array ADJUDICATED_BY_2547 = [
        // One aggregate, bound by foreign keys and cross-table triggers: the
        // whole set stays on the serving side or none of it does.
        'waaseyaa_config_activation' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_activation_counter' => RuntimeTablePolicy::Preserve,
        'waaseyaa_config_activation_manifest' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_activation_v2' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_candidate' => RuntimeTablePolicy::Preserve,
        'waaseyaa_config_candidate_sweep_fence' => RuntimeTablePolicy::Preserve,
        'waaseyaa_config_entry' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_entry_contract' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_entry_v2' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_generation' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_generation_v2' => RuntimeTablePolicy::AppendOnly,
        'waaseyaa_config_manifest_replay' => RuntimeTablePolicy::Preserve,

        // Scheduler state. The fence sequence is a correctness concern, not a
        // retention one: a reset lets a stale lease holder out-fence the live.
        'waaseyaa_scheduler_effect_fences' => RuntimeTablePolicy::Preserve,
        'waaseyaa_scheduler_fence_sequence' => RuntimeTablePolicy::Preserve,
        'waaseyaa_scheduler_leases' => RuntimeTablePolicy::Preserve,
        'waaseyaa_scheduler_occurrence_outbox' => RuntimeTablePolicy::Preserve,
        'waaseyaa_scheduler_occurrences' => RuntimeTablePolicy::Preserve,

        'publishing_idempotency' => RuntimeTablePolicy::Preserve,
        'state' => RuntimeTablePolicy::Preserve,

        // Counts the migration ledger, which is itself artifact-owned.
        'waaseyaa_schema_authority' => RuntimeTablePolicy::Artifact,

        // Needs BOTH sides: the serving aggregate versions must not regress,
        // and an artifact-only entity with no row cannot be hydrated at all.
        'waaseyaa_entity_mutation_authority' => RuntimeTablePolicy::IdentityMerge,

        'cache_items' => RuntimeTablePolicy::Artifact,
    ];

    #[Test]
    public function the_2547_classifications_are_recorded_exactly(): void
    {
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();

        foreach (self::ADJUDICATED_BY_2547 as $table => $expected) {
            self::assertArrayHasKey($table, $definitions, $table . ' must be classified (#2547).');
            self::assertSame(
                $expected,
                $definitions[$table]->policy,
                sprintf(
                    '%s is classified %s. Changing it decides whether a serving host keeps its rows '
                    . 'or takes the artifact rows — make that change deliberately, with the reason in the '
                    . 'catalogue comment beside it.',
                    $table,
                    $expected->value,
                ),
            );
        }
    }

    /**
     * The version is what a consumer's install evidence records, so it must
     * move when the classifications do.
     */
    #[Test]
    public function the_version_advanced_for_the_2547_adjudication(): void
    {
        self::assertGreaterThanOrEqual(2, FrameworkRuntimeTableCatalogue::VERSION);
    }

    #[Test]
    public function definitions_are_unique_and_ordered_by_name(): void
    {
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();
        $names = array_keys($definitions);

        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'Definitions are returned sorted by table name.');
        self::assertSame(count($names), count(array_unique($names)));

        foreach ($definitions as $name => $definition) {
            self::assertSame($name, $definition->name, 'The index must be the definition name.');
        }
    }

    /**
     * A definition that names an account-reference column commits the preparer
     * to validating it against the `user` table, so the column list must be
     * real column names and the sentinels must be non-negative.
     */
    #[Test]
    public function account_reference_declarations_are_well_formed(): void
    {
        $withReferences = 0;
        foreach (new FrameworkRuntimeTableCatalogue()->definitions() as $definition) {
            foreach ($definition->accountReferenceColumns as $column) {
                self::assertMatchesRegularExpression('/^[a-z_][a-z0-9_]*$/', $column);
                $withReferences++;
            }
            foreach ($definition->allowedAccountReferenceValues as $sentinel) {
                self::assertGreaterThanOrEqual(0, $sentinel);
            }
        }

        self::assertGreaterThan(0, $withReferences, 'The fixture would prove nothing with no declarations.');
    }

    /**
     * Artifact means the serving rows are DISCARDED, so the set of tables
     * carrying that policy is the one worth stating outright.
     */
    #[Test]
    public function only_rebuildable_or_build_owned_tables_take_the_artifact_policy(): void
    {
        $artifactTables = [];
        foreach (new FrameworkRuntimeTableCatalogue()->definitions() as $name => $definition) {
            if ($definition->policy === RuntimeTablePolicy::Artifact) {
                $artifactTables[] = $name;
            }
        }
        sort($artifactTables, SORT_STRING);

        self::assertSame([
            'cache_discovery',
            'cache_items',
            'cache_mcp_read',
            'cache_render',
            'embeddings',
            'migration_id_map',
            'migration_run_state',
            'search_index',
            'search_index_config',
            'search_index_content',
            'search_index_data',
            'search_index_docsize',
            'search_index_idx',
            'search_metadata',
            'waaseyaa_migrations',
            'waaseyaa_schema_authority',
        ], $artifactTables, 'Adding a table here discards the serving host rows for that table.');
    }
}
