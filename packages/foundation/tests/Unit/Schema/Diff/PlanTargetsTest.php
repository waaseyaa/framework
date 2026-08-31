<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Schema\Diff;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\AddForeignKey;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Diff\DropColumn;
use Waaseyaa\Foundation\Schema\Diff\ForeignKeySpec;
use Waaseyaa\Foundation\Schema\Diff\OpKind;
use Waaseyaa\Foundation\Schema\Diff\PlanTargets;
use Waaseyaa\Foundation\Schema\Diff\RenameColumn;
use Waaseyaa\Foundation\Schema\Diff\RenameTable;

/**
 * The prerequisite-table contract behind FW-2701's targeted materialization.
 *
 * What this list contains decides what gets created before a plan runs, so each
 * op kind is pinned individually rather than through a single happy path.
 *
 * @see docs/change-records/FW-2701.md — C1
 */
#[CoversClass(PlanTargets::class)]
final class PlanTargetsTest extends TestCase
{
    #[Test]
    public function a_rename_requires_only_its_source(): void
    {
        // Naming the destination would have the materializer create the table
        // the rename is about to produce, and the rename then collides.
        self::assertSame(
            ['old_account'],
            PlanTargets::prerequisitesForOp(new RenameTable('old_account', 'account')),
        );
    }

    #[Test]
    public function a_foreign_key_requires_both_ends(): void
    {
        self::assertSame(
            ['profile', 'account'],
            PlanTargets::prerequisitesForOp(new AddForeignKey(
                'profile',
                new ForeignKeySpec('account', ['account_id'], ['eid']),
            )),
        );
    }

    #[Test]
    public function single_table_ops_require_their_own_table(): void
    {
        $spec = new ColumnSpec(type: 'text', nullable: true);

        self::assertSame(['account'], PlanTargets::prerequisitesForOp(new AddColumn('account', 'a', $spec)));
        self::assertSame(['account'], PlanTargets::prerequisitesForOp(new DropColumn('account', 'a')));
        self::assertSame(['account'], PlanTargets::prerequisitesForOp(new AddIndex('account', ['a'])));
        self::assertSame(['account'], PlanTargets::prerequisitesForOp(new RenameColumn('account', 'a', 'b')));
    }

    #[Test]
    public function a_composite_deduplicates_and_preserves_first_occurrence_order(): void
    {
        $spec = new ColumnSpec(type: 'text', nullable: true);
        $diff = new CompositeDiff([
            new AddColumn('profile', 'a', $spec),
            new AddColumn('account', 'b', $spec),
            new AddColumn('profile', 'c', $spec),
        ]);

        self::assertSame(['profile', 'account'], PlanTargets::prerequisiteTables($diff));
    }

    #[Test]
    public function an_empty_composite_requires_nothing(): void
    {
        self::assertSame([], PlanTargets::prerequisiteTables(new CompositeDiff()));
    }

    #[Test]
    public function every_op_kind_is_handled(): void
    {
        // An unhandled kind must be a deliberate, reviewable addition rather
        // than a silent "this plan touches nothing".
        $handled = [];
        foreach (OpKind::cases() as $kind) {
            $handled[] = $kind->value;
        }

        self::assertCount(9, $handled, 'a new op kind requires an explicit PlanTargets rule');
    }
}
