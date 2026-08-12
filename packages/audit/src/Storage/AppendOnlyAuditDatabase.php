<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

/**
 * Active append-only enforcement for the OCAP audit log at the database layer.
 *
 * Decorates a real {@see DatabaseInterface} and refuses any `UPDATE` or `DELETE`
 * targeting the `audit_event` table, throwing {@see \LogicException}. Inserts,
 * reads, and all access to other tables pass through untouched. This is the
 * structural guarantee behind the append-only invariant (FR-003): the
 * {@see \Waaseyaa\Audit\Writer\AuditEventWriter} is wired with this decorator,
 * so the only mutation it can express is an append.
 *
 * Schema access is wrapped by {@see AppendOnlySchema}. Runtime callers may
 * inspect schema state, but every DDL operation is refused. Audit schema
 * changes belong exclusively to the migration coordinator and therefore use
 * its raw database connection rather than this runtime decorator.
 *
 * Raw SQL is guarded too (FR-008, #1648): {@see query()} normalizes the SQL —
 * removing single-quoted string literals and SQL comments, and UNQUOTING
 * identifier delimiters (`"…"`, `` `…` ``, `[…]` — the three forms SQLite
 * accepts) so a quoted table name is still seen — then throws the same
 * {@see \LogicException} when a mutation verb (UPDATE / DELETE / DROP / ALTER /
 * TRUNCATE) co-occurs with an append-only table name in the remainder. This
 * closes the identifier-quoting bypass where `DELETE FROM "audit_event"` (or
 * the backtick / bracket forms, or `main."audit_event"`) previously slipped
 * past because the double-quoted name was stripped as if it were a string
 * literal. SELECTs over audit_event (including literals that merely *contain*
 * mutation verbs, e.g. `WHERE attributes LIKE '%delete%'`, and plain
 * `SELECT … FROM "audit_event"`), INSERTs, and mutations of non-audit tables
 * pass through. The guard is deliberately fail-closed on residual ambiguity: a
 * CTE-wrapped mutation (`WITH x AS (...) DELETE FROM audit_event`) throws, and
 * so does a pathological SELECT joining audit_event with an identifier
 * literally named `delete` — accepted for an append-only guarantee (contract
 * clause 23).
 *
 * The decorator (not a database trigger) is the enforcement layer by design:
 * the sole sanctioned deletion — `audit:prune` — runs through the RAW
 * {@see DatabaseInterface}, so a blanket trigger would block retention too.
 * Caller discrimination (writer ⇒ decorator ⇒ blocked; prune ⇒ raw ⇒ allowed)
 * can only live here.
 *
 * The one sanctioned bulk-delete path — `audit:prune` retention purging
 * ({@see \Waaseyaa\CLI\Command\Audit\PruneCommand}) — deliberately resolves the
 * raw {@see DatabaseInterface} from the container, not this decorator, so
 * retention works while every writer path stays immutable.
 *
 * Replaces the former `AppendOnlyDriverGuard`, which guarded the entity-storage
 * driver — a path that no longer exists now that `audit_event` is a plain OCAP
 * log table rather than a registered content entity.
 *
 * @api
 */
final class AppendOnlyAuditDatabase implements DatabaseInterface
{
    /**
     * Tables on which UPDATE and DELETE are forbidden through this decorator.
     *
     * `audit_checkpoint` joins `audit_event` so that the tamper-evidence chain
     * rows are protected by the same structural guard. The checkpoint builder
     * (WP2) and prune command write through the RAW DatabaseInterface, not
     * this decorator — the same discrimination already used for audit_event.
     *
     * @var list<string>
     */
    private const APPEND_ONLY_TABLES = [
        'audit_event',
        'audit_checkpoint',
        'privileged_read_ledger',
        // The strict reserve/finalize ledger (#2177 F4). Append-only for the
        // same reason as the others: a reservation that could be updated or
        // deleted would let a mutation's evidence be rewritten after the fact.
        'strict_audit_ledger',
        // The operation-approval event log (#2177 F1). A decision or a
        // consumption that could be updated or deleted would let an approval
        // be forged, revoked-after-use, or reused.
        'mcp_approval_event',
    ];

    /**
     * SQL verbs that mutate rows or schema — forbidden against append-only
     * tables through {@see query()}.
     *
     * @var list<string>
     */
    private const MUTATION_VERBS = ['UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE'];

    public function __construct(
        private readonly DatabaseInterface $inner,
    ) {}

    public function select(string $table, string $alias = ''): SelectInterface
    {
        return $this->inner->select($table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        return $this->inner->insert($table);
    }

    public function update(string $table): UpdateInterface
    {
        $this->assertMutable($table, 'UPDATE');

        return $this->inner->update($table);
    }

    public function delete(string $table): DeleteInterface
    {
        $this->assertMutable($table, 'DELETE');

        return $this->inner->delete($table);
    }

    public function schema(): SchemaInterface
    {
        return new AppendOnlySchema($this->inner->schema(), self::APPEND_ONLY_TABLES);
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        return $this->inner->transaction($name);
    }

    /** @return \Traversable<int|string, mixed> */
    public function query(string $sql, array $args = []): \Traversable
    {
        $this->assertQueryAppendOnly($sql);

        return $this->inner->query($sql, $args);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }

    private function assertMutable(string $table, string $operation): void
    {
        if (in_array($table, self::APPEND_ONLY_TABLES, true)) {
            throw new \LogicException($this->appendOnlyViolationMessage($table, $operation));
        }
    }

    /**
     * Raw-SQL arm of the append-only guarantee (FR-008, #1648).
     *
     * Token-level check, not a SQL parse: after stripping string literals and
     * comments, a word-boundary mutation verb co-occurring with a
     * word-boundary append-only table name throws. Conjunctive by design —
     * mutations of non-audit tables and SELECT/INSERT traffic over
     * audit_event pass through; residual ambiguity fails closed (clause 23).
     */
    private function assertQueryAppendOnly(string $sql): void
    {
        $stripped = $this->normalizeSqlForGuard($sql);

        $verb = null;
        foreach (self::MUTATION_VERBS as $candidate) {
            if (preg_match('/\b' . $candidate . '\b/i', $stripped) === 1) {
                $verb = $candidate;
                break;
            }
        }

        if ($verb === null) {
            return;
        }

        foreach (self::APPEND_ONLY_TABLES as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $stripped) === 1) {
                throw new \LogicException($this->appendOnlyViolationMessage($table, $verb));
            }
        }
    }

    /**
     * Normalize SQL so the verb+table guard sees the real statement structure.
     *
     * One left-to-right pass with correct quote-context precedence:
     *   - single-quoted string literals → removed. Payload text, not statement
     *     structure: a mutation verb inside one (`WHERE attributes LIKE
     *     '%delete%'` — `attributes` is JSON TEXT) must never false-positive.
     *   - SQL comments (`--` line, slash-star block) → removed.
     *   - identifier quotes — `"…"`, `` `…` `` and `[…]`, the three forms SQLite
     *     accepts — are UNQUOTED: the delimiters are dropped and the inner name
     *     kept, so a quoted append-only table (`"audit_event"`, `` `audit_event` ``,
     *     `[audit_event]`, `main."audit_event"`) is still matched by the
     *     table-name check.
     *
     * The prior implementation stripped double-quoted spans as if they were
     * string literals (#1648), which deleted the table name from the guard's
     * view and let `DELETE FROM "audit_event"` slip through to the inner
     * database. Matching the literal/comment alternatives FIRST means a quote
     * character living inside a string literal or comment is consumed there and
     * never mistaken for an identifier delimiter.
     */
    private function normalizeSqlForGuard(string $sql): string
    {
        $result = preg_replace_callback(
            '/(\'(?:[^\']|\'\')*\')'        // 1: single-quoted string literal
            . '|(--[^\r\n]*|\/\*.*?\*\/)'   // 2: line / block comment
            . '|"((?:[^"]|"")*)"'           // 3: "double-quoted identifier"
            . '|`((?:[^`]|``)*)`'           // 4: `backtick identifier`
            . '|\[([^\]]*)\]/s',            // 5: [bracket identifier]
            static function (array $m): string {
                // Group 1 (single-quoted literal) and group 2 (comment) → erase.
                // Both offsets are always present in the match array once group 1
                // is known not to have matched ('' otherwise), so no
                // null-coalesce is needed (PCRE keeps non-trailing groups).
                if ($m[1] !== '' || $m[2] !== '') {
                    return ' ';
                }

                // Otherwise an identifier branch matched: keep the inner name,
                // space-padded so adjacent tokens never fuse
                // (`FROM"audit_event"` → `FROM audit_event`). Offset 3 is always
                // present here; the backtick/bracket groups are trailing-optional.
                return ' ' . $m[3] . ($m[4] ?? '') . ($m[5] ?? '') . ' ';
            },
            $sql,
        );

        // preg_replace_callback returns null only on PCRE failure; fall back to
        // the raw SQL so the verb/table check still runs (fail-closed).
        return $result ?? $sql;
    }

    /**
     * Single message factory shared by the builder-level guard
     * ({@see assertMutable()}) and the raw-SQL guard
     * ({@see assertQueryAppendOnly()}) — contract clause 21 requires the
     * same \LogicException from both paths.
     */
    private function appendOnlyViolationMessage(string $table, string $operation): string
    {
        return sprintf(
            'Audit table "%s" is append-only (OCAP FR-003): %s is forbidden through the audit '
            . 'database. Records may only be appended; bulk retention deletion goes through '
            . 'audit:prune via the raw DatabaseInterface.',
            $table,
            $operation,
        );
    }
}
