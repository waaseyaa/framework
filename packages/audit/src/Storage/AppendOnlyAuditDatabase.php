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
 * reads, schema operations, and all access to other tables pass through
 * untouched. This is the structural guarantee behind the append-only invariant
 * (FR-003): the {@see \Waaseyaa\Audit\Writer\AuditEventWriter} is wired with this
 * decorator, so the only mutation it can express is an append.
 *
 * Raw SQL is guarded too (FR-008, #1648): {@see query()} strips string
 * literals and SQL comments, then throws the same {@see \LogicException} when
 * a mutation verb (UPDATE / DELETE / DROP / ALTER / TRUNCATE) co-occurs with
 * an append-only table name in the remainder. SELECTs over audit_event
 * (including literals that merely *contain* mutation verbs, e.g.
 * `WHERE attributes LIKE '%delete%'`), INSERTs, and mutations of non-audit
 * tables pass through. The guard is deliberately fail-closed on residual
 * ambiguity: a CTE-wrapped mutation (`WITH x AS (...) DELETE FROM
 * audit_event`) throws, and so does a pathological SELECT joining
 * audit_event with an identifier literally named `delete` — accepted for an
 * append-only guarantee (contract clause 23).
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
     * @var list<string>
     */
    private const APPEND_ONLY_TABLES = ['audit_event'];

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
        return $this->inner->schema();
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
        $stripped = $this->stripLiteralsAndComments($sql);

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
     * Remove single-quoted/double-quoted string literals and SQL comments
     * (`--` line comments and slash-star block comments) so that mutation
     * verbs inside payload
     * text (`WHERE attributes LIKE '%delete%'` — attributes is JSON TEXT)
     * never false-positive. One alternation pass keeps left-to-right
     * precedence between quote and comment openers; `''` / `""` doubling is
     * honoured inside literals.
     */
    private function stripLiteralsAndComments(string $sql): string
    {
        return (string) preg_replace(
            '/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|--[^\r\n]*|\/\*.*?\*\//s',
            ' ',
            $sql,
        );
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
