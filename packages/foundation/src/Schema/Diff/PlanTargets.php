<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Schema\Diff;

/**
 * Enumerates the physical tables a {@see CompositeDiff} touches.
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
     * Every table named by the composite, in deterministic order.
     *
     * @return list<string>
     */
    public static function tables(CompositeDiff $diff): array
    {
        $tables = [];
        foreach ($diff->ops as $op) {
            foreach (self::tablesForOp($op) as $table) {
                if ($table !== '' && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    public static function tablesForOp(SchemaDiffOp $op): array
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
            // A rename needs the source to exist; the destination is what the
            // rest of the plan will address afterwards.
            OpKind::RenameTable => [
                self::str($canonical, 'from'),
                self::str($canonical, 'to'),
            ],
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
