<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Schema\Diff;

/**
 * Answers two different questions about the tables a {@see CompositeDiff} names.
 *
 * **Prerequisites** are the tables an operation requires to already exist.
 * **Affected** tables are the ones it changes. They are not the same set, and
 * conflating them is a defect: a rename *requires* its source but *changes*
 * both its source and its destination. Materialization must use prerequisites,
 * or it pre-creates the table the rename is about to produce; uncertainty
 * tracking must use affected tables, or a later operation is judged against a
 * table an earlier one already altered.
 *
 * Reads each op's canonical dictionary rather than its implementation class, so
 * the closed op set stays enforced in one place ({@see OpKind}) and adding an op
 * kind here is a deliberate, reviewable act. Every kind must be handled: an
 * unhandled kind throws rather than silently reporting no targets, because a
 * missed target would let a caller conclude a plan touches nothing.
 *
 * Table order follows op order, de-duplicated, first occurrence wins, so callers
 * get a deterministic list suitable for logging and fixtures.
 *
 * @see docs/change-records/FW-2701.md — C1 targeted materialization
 */
final readonly class PlanTargets
{
    /**
     * Every table the composite requires to pre-exist, in deterministic order.
     *
     * @return list<string>
     */
    public static function prerequisiteTables(CompositeDiff $diff): array
    {
        $tables = [];
        // Ops are walked in authored order so a table an EARLIER op produces is
        // never reported as a prerequisite of a later one. Without this, a plan
        // that renames `old` to `new` and then alters `new` would have `new`
        // materialized before the rename ran, and the rename would collide.
        $produced = [];

        foreach ($diff->ops as $op) {
            foreach (self::prerequisitesForOp($op) as $table) {
                if ($table === '' || in_array($table, $produced, true)) {
                    continue;
                }
                if (!in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
            foreach (self::producedByOp($op) as $table) {
                if ($table !== '' && !in_array($table, $produced, true)) {
                    $produced[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * Tables this op brings into existence, which later ops therefore must not
     * treat as prerequisites.
     *
     * @return list<string>
     */
    private static function producedByOp(SchemaDiffOp $op): array
    {
        return $op->kind() === OpKind::RenameTable
            ? [self::str($op->toCanonical(), 'to')]
            : [];
    }

    /**
     * Every table this composite changes, in deterministic order.
     *
     * Distinct from {@see prerequisiteTables()}: this is what becomes unknown to
     * anything reasoning about state after the composite runs.
     *
     * @return list<string>
     */
    public static function affectedTables(CompositeDiff $diff): array
    {
        $tables = [];
        foreach ($diff->ops as $op) {
            foreach (self::affectedByOp($op) as $table) {
                if ($table !== '' && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * Every table one op changes.
     *
     * A rename changes both ends: the source stops existing and the destination
     * starts. A foreign key changes only the table carrying the constraint —
     * the referenced table is a prerequisite, not a casualty.
     *
     * @return list<string>
     */
    public static function affectedByOp(SchemaDiffOp $op): array
    {
        $canonical = $op->toCanonical();

        return match ($op->kind()) {
            OpKind::RenameTable => [
                self::str($canonical, 'from'),
                self::str($canonical, 'to'),
            ],
            default => [self::str($canonical, 'table')],
        };
    }

    /**
     * @return list<string>
     */
    public static function prerequisitesForOp(SchemaDiffOp $op): array
    {
        $canonical = $op->toCanonical();

        return match ($op->kind()) {
            OpKind::AddColumn,
            OpKind::AlterColumn,
            OpKind::DropColumn,
            OpKind::AddIndex,
            OpKind::DropIndex,
            OpKind::DropForeignKey,
            OpKind::RenameColumn => [self::str($canonical, 'table')],
            // The referenced table must be present before the constraint is
            // created, so it is a genuine target, not incidental metadata.
            OpKind::AddForeignKey => [
                self::str($canonical, 'table'),
                self::str(self::arr($canonical, 'spec'), 'referenced_table'),
            ],
            // Only the SOURCE is a prerequisite. The destination is what the
            // rename itself produces — materializing it first would make the
            // rename collide with a table that should not exist yet, breaking
            // renames that worked before targeted materialization existed.
            OpKind::RenameTable => [self::str($canonical, 'from')],
        };
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private static function str(array $canonical, string $key): string
    {
        $value = $canonical[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private static function arr(array $canonical, string $key): array
    {
        $value = $canonical[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
