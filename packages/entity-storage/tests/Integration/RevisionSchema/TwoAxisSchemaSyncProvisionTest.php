<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\RevisionSchema;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\Tests\Fixtures\PlainContentRevisionableEntity;

/**
 * Release-gate acceptance for P0-3 schema provisioning (mission
 * wayfinding-stress-remediation-01KVGK4Q): the schema-sync runner that
 * `db:init` now invokes by default materializes ALL THREE tables of a two-axis
 * (revisionable × translatable) sql-blob entity type — the exact shape of the
 * saved-trail `wayfinding_trail`:
 *   - base table              `<id>`
 *   - revision table          `<id>_revision`
 *   - translation-revision    `<id>__translation__revision`
 *
 * The alpha.233 stress test found a fresh `db:init` created none of these (it
 * ran migrations only); the trail tables needed a manual `schema:sync` /
 * `revisions:enable wayfinding_trail`. `db:init` now runs this runner by
 * default, so this test pins the table-creation guarantee the default relies on.
 */
#[CoversClass(EntitySchemaSyncRunner::class)]
#[CoversClass(EntitySchemaSync::class)]
final class TwoAxisSchemaSyncProvisionTest extends TestCase
{
    private function makeSqliteDatabase(): DBALDatabase
    {
        return new DBALDatabase(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    }

    /** A revisionable + translatable sql-blob type, mirroring `wayfinding_trail`. */
    private function twoAxisTrailLikeType(string $id = 'wf_demo_trail'): EntityType
    {
        return new EntityType(
            id: $id,
            label: 'Demo Trail',
            // Any ContentEntityBase subclass satisfies the translatable-type
            // class validation; the sql-blob runner never instantiates it.
            class: PlainContentRevisionableEntity::class,
            keys: [
                'id' => 'tid',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'revision_id',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            translatable: true,
        );
    }

    #[Test]
    public function runner_provisions_all_three_two_axis_tables(): void
    {
        $db = $this->makeSqliteDatabase();
        $type = $this->twoAxisTrailLikeType();

        new EntitySchemaSyncRunner($db)->run([$type]);

        self::assertTrue($db->schema()->tableExists('wf_demo_trail'), 'base table must be created');
        self::assertTrue($db->schema()->tableExists('wf_demo_trail_revision'), 'revision table must be created');
        self::assertTrue(
            $db->schema()->tableExists('wf_demo_trail__translation__revision'),
            'two-axis translation-revision table must be created — the table the stress test had to create manually',
        );
    }

    #[Test]
    public function provisioning_is_idempotent(): void
    {
        $db = $this->makeSqliteDatabase();
        $type = $this->twoAxisTrailLikeType('wf_idem_trail');

        // A second run must not throw and the tables must still be present —
        // exactly db:init's "safe to run on every deploy" guarantee.
        new EntitySchemaSyncRunner($db)->run([$type]);
        new EntitySchemaSyncRunner($db)->run([$type]);

        self::assertTrue($db->schema()->tableExists('wf_idem_trail'));
        self::assertTrue($db->schema()->tableExists('wf_idem_trail_revision'));
        self::assertTrue($db->schema()->tableExists('wf_idem_trail__translation__revision'));
    }

    #[Test]
    public function single_axis_revisionable_type_gets_no_translation_revision_table(): void
    {
        // Guard: the translation-revision table is gated on BOTH axes, so a
        // revisionable-only type's schema is unchanged (no leakage).
        $db = $this->makeSqliteDatabase();
        $type = new EntityType(
            id: 'wf_single_axis',
            label: 'Single Axis',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id'],
            revisionable: true,
            translatable: false,
        );

        new EntitySchemaSyncRunner($db)->run([$type]);

        self::assertTrue($db->schema()->tableExists('wf_single_axis'));
        self::assertTrue($db->schema()->tableExists('wf_single_axis_revision'));
        self::assertFalse($db->schema()->tableExists('wf_single_axis__translation__revision'));
    }
}
