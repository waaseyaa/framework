<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * Reads facts about a table that SQLite exposes only through its stored DDL.
 *
 * `PRAGMA table_info` reports name, type, nullability and default, but **not**
 * collation. Collation decides an index's uniqueness and ordering semantics, so
 * a precondition check that ignores it can accept an index that behaves
 * differently from the authored one. The stored `sqlite_master.sql` is the only
 * read-only source for it.
 *
 * Parsing DDL is unavoidable here, so the parse is written to be *authoritative
 * or silent*: it scans the column-definition list with quote, string, comment
 * and nesting awareness rather than pattern-matching, and it distinguishes three
 * outcomes rather than two:
 *
 * - a column that declares `COLLATE X` → `X`;
 * - a column that declares none → `BINARY`, which is authoritative because it is
 *   SQLite's documented default, not a guess;
 * - anything it cannot resolve — table absent, DDL unreadable, column not found,
 *   a construct the scanner does not model → **unknown**, reported as `null`.
 *
 * Callers must treat unknown as "cannot establish equivalence" and fail closed.
 * Defaulting unknown to `BINARY` would silently accept a mismatched index, which
 * is the failure this class exists to prevent.
 *
 * @see docs/change-records/FW-2701.md
 */
final readonly class SqliteTableDefinition
{
    private const CONSTRAINT_KEYWORDS = [
        'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN',
    ];

    public function __construct(private string $sql) {}

    /**
     * Collation of one column, or null when it cannot be established.
     */
    public function collationOf(string $column): ?string
    {
        $body = self::columnDefinitionList($this->sql);
        if ($body === null) {
            return null;
        }

        foreach (self::splitTopLevel($body) as $definition) {
            $identifier = self::leadingIdentifier($definition);
            if ($identifier === null) {
                continue;
            }
            [$name, $quoted] = $identifier;
            // A *bare* leading keyword introduces a table-level constraint. A
            // quoted one is unambiguously a column name — `"check"` is legal.
            if (!$quoted && in_array(strtoupper($name), self::CONSTRAINT_KEYWORDS, true)) {
                continue;
            }
            if (strcasecmp($name, $column) !== 0) {
                continue;
            }

            [$found, $collation] = self::collateToken($definition);
            if (!$found) {
                // No COLLATE clause: BINARY is SQLite's documented default, so
                // this is authoritative rather than an assumption.
                return 'BINARY';
            }

            // A COLLATE clause we could not read is unknown, never BINARY.
            return $collation;
        }

        return null;
    }

    /**
     * The text between the outermost parentheses of a stored table-creation
     * statement.
     *
     * This class only ever reads DDL; it issues none.
     */
    private static function columnDefinitionList(string $sql): ?string
    {
        $depth = 0;
        $start = null;
        foreach (self::significantOffsets($sql) as $offset => $char) {
            if ($char === '(') {
                if ($depth === 0) {
                    $start = $offset + 1;
                }
                ++$depth;
                continue;
            }
            if ($char === ')') {
                --$depth;
                if ($depth === 0 && $start !== null) {
                    return substr($sql, $start, $offset - $start);
                }
                if ($depth < 0) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Split on commas that are not nested inside parentheses or quoted text.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($body);
        $significant = self::significantOffsets($body);

        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $significant[$offset] ?? null;
            if ($char === null) {
                // Inside a quoted region or comment: copy verbatim.
                $current .= $body[$offset];
                continue;
            }
            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Offsets of characters that are outside string literals, quoted
     * identifiers and comments, keyed by offset.
     *
     * @return array<int, string>
     */
    private static function significantOffsets(string $sql): array
    {
        $result = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if ($char === '-' && $offset + 1 < $length && $sql[$offset + 1] === '-') {
                $newline = strpos($sql, "\n", $offset);
                $offset = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '/' && $offset + 1 < $length && $sql[$offset + 1] === '*') {
                $end = strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $offset = self::skipQuoted($sql, $offset, $char, $char);
                continue;
            }
            if ($char === '[') {
                $offset = self::skipQuoted($sql, $offset, '[', ']');
                continue;
            }

            $result[$offset] = $char;
            ++$offset;
        }

        return $result;
    }

    private static function skipQuoted(string $sql, int $offset, string $open, string $close): int
    {
        $length = strlen($sql);
        ++$offset;
        while ($offset < $length) {
            if ($sql[$offset] === $close) {
                // A doubled closing character is an escaped literal, not the end.
                if ($open === $close && $offset + 1 < $length && $sql[$offset + 1] === $close) {
                    $offset += 2;
                    continue;
                }

                return $offset + 1;
            }
            ++$offset;
        }

        return $length;
    }

    /**
     * The first identifier in a column definition, unquoted, plus whether it was
     * written quoted.
     *
     * Quoting is load-bearing: a bare `CHECK` starts a table constraint, while
     * `"check"` is a column whose name happens to be a keyword.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function leadingIdentifier(string $definition): ?array
    {
        $trimmed = ltrim($definition);
        if ($trimmed === '') {
            return null;
        }

        $first = $trimmed[0];
        foreach ([['"', '"'], ['`', '`'], ['[', ']']] as [$open, $close]) {
            if ($first !== $open) {
                continue;
            }
            $end = self::skipQuoted($trimmed, 0, $open, $close);
            if ($end <= 1) {
                return null;
            }
            $inner = substr($trimmed, 1, $end - 2);

            return [$open === $close ? str_replace($open . $open, $open, $inner) : $inner, true];
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_$]*/', $trimmed, $matches) === 1
            ? [$matches[0], false]
            : null;
    }

    /**
     * Whether the column definition carries a top-level `COLLATE` clause, and
     * its argument.
     *
     * Occurrences inside a string literal, a comment, or a nested expression
     * such as a CHECK clause are not the column's collation and are ignored.
     * Returns `[false, null]` when there is no clause — the caller then applies
     * SQLite's documented `BINARY` default — and `[true, null]` when a clause is
     * present but unreadable, which is unknown.
     *
     * @return array{0: bool, 1: string|null}
     */
    private static function collateToken(string $definition): array
    {
        $significant = self::significantOffsets($definition);
        $flat = '';
        $map = [];
        foreach ($significant as $offset => $char) {
            $map[strlen($flat)] = $offset;
            $flat .= $char;
        }

        $depth = 0;
        $length = strlen($flat);
        for ($index = 0; $index < $length; ++$index) {
            $char = $flat[$index];
            if ($char === '(') {
                ++$depth;
                continue;
            }
            if ($char === ')') {
                --$depth;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            // `\b` so COLLATED does not match, `\s*` so a clause with no
            // argument is still *found* — and therefore reported unknown rather
            // than falling through to the BINARY default.
            if (preg_match('/\GCOLLATE\b\s*/iA', $flat, $matches, 0, $index) !== 1) {
                continue;
            }
            $after = $index + strlen($matches[0]);
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/A', $flat, $name, 0, $after) === 1) {
                return [true, strtoupper($name[0])];
            }

            return [true, null];
        }

        return [false, null];
    }
}
