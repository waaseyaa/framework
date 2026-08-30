<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\LedgerSchema;

use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Authoring record for the FW-2701 ledger-schema upgrade.
 *
 * Adds the nullable `apply_mode` column to `waaseyaa_migrations`, recording
 * whether a v2 node issued SQL (`applied`) or found the live schema already
 * exactly satisfying its declared operations (`already_satisfied`).
 *
 * This is audit evidence, not identity. `checksum` and `diff_hash` remain
 * functions of the authored plan alone and are unchanged in both cases, so
 * replay guarding and verification are untouched. §15 Q1's refusal of a second
 * *identifier* column is therefore not reopened.
 *
 * **Self-application caveat** is the same as
 * {@see V2_0001_add_checksum_columns}: the ledger cannot use the Migrator to
 * apply its own schema change, because the row recording the apply needs the
 * column to exist first. The runtime effect is applied idempotently by
 * {@see \Waaseyaa\Foundation\Migration\MigrationRepository::ensureCurrentSchema()};
 * this class documents the structural intent in the canonical algebra and locks
 * its canonical-JSON shape against drift.
 */
final readonly class V2_0002_add_apply_mode_column implements MigrationInterfaceV2
{
    public function migrationId(): string
    {
        return 'waaseyaa/foundation:v2:ledger-add-apply-mode-column';
    }

    public function package(): string
    {
        return 'waaseyaa/foundation';
    }

    public function dependencies(): array
    {
        return ['waaseyaa/foundation:v2:ledger-add-checksum-columns'];
    }

    public function plan(): MigrationPlan
    {
        return new MigrationPlan(
            migrationId: $this->migrationId(),
            package: $this->package(),
            dependencies: $this->dependencies(),
            root: new CompositeDiff([
                new AddColumn(
                    'waaseyaa_migrations',
                    'apply_mode',
                    new ColumnSpec(type: 'varchar', nullable: true, length: 32),
                ),
            ]),
        );
    }
}
