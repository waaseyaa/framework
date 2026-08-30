<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * Reads facts about a table that SQLite exposes only through its stored schema.
 *
 * `PRAGMA table_info` reports name, type, nullability and default, but **not**
 * collation. Collation decides an index's uniqueness and ordering semantics, so
 * a precondition check that ignores it can accept an index that behaves
 * differently from the authored one. The stored definition is the only
 * read-only source for it.
 *
 * **This class reads schema text. It issues none.**
 *
 * Interpretation is token-preserving. An earlier character-flattening approach
 * was wrong in kind, not merely in detail: discarding comments concatenated the
 * tokens either side of them, and scanning for a keyword by position matched it
 * inside longer identifiers. Both produced a *confident* answer that disagreed
 * with SQLite, which is the one failure this class must never have.
 *
 * Three outcomes, never two:
 *
 * - a column whose definition carries a `COLLATE` clause → that collation
 *   (the **last** clause, which is the one SQLite applies);
 * - a column carrying none → `BINARY`, authoritative because it is SQLite's
 *   documented default rather than a guess;
 * - anything unresolved — table absent, schema text unreadable, column not
 *   found, a `COLLATE` clause whose argument is not an identifier → **unknown**,
 *   reported as `null`.
 *
 * Callers must treat unknown as "cannot establish equivalence" and fail closed.
 * Collapsing unknown to `BINARY` would silently accept a mismatched index.
 *
 * @see docs/change-records/FW-2701.md
 */
final readonly class SqliteTableDefinition
{
    /** Leading bare keywords that introduce a table constraint, not a column. */
    private const CONSTRAINT_KEYWORDS = [
        'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN',
    ];

    private const TYPE_IDENTIFIER = 'identifier';
    private const TYPE_QUOTED = 'quoted';
    private const TYPE_STRING = 'string';
    private const TYPE_PUNCTUATION = 'punctuation';

    public function __construct(private string $sql) {}

    /**
     * Collation of one column, or null when it cannot be established.
     */
    public function collationOf(string $column): ?string
    {
        $definitions = self::columnDefinitions(self::tokenize($this->sql));
        if ($definitions === null) {
            return null;
        }

        foreach ($definitions as $definition) {
            $first = $definition[0] ?? null;
            if ($first === null) {
                continue;
            }
            if ($first['type'] !== self::TYPE_IDENTIFIER && $first['type'] !== self::TYPE_QUOTED) {
                continue;
            }
            // A *bare* leading keyword introduces a table constraint. A quoted
            // one is unambiguously a column name — `"check"` is legal.
            if ($first['type'] === self::TYPE_IDENTIFIER
                && in_array(strtoupper($first['value']), self::CONSTRAINT_KEYWORDS, true)
            ) {
                continue;
            }
            if (strcasecmp($first['value'], $column) !== 0) {
                continue;
            }

            return self::collationIn($definition);
        }

        return null;
    }

    /**
     * The collation a column definition declares.
     *
     * The whole definition is examined before answering, because a later clause
     * supersedes an earlier one. `COLLATE` counts only as a standalone token at
     * the definition's own nesting level, so an occurrence inside an identifier,
     * a string, or a parenthesised expression such as `CHECK (…)` or a default
     * expression is correctly not the column's collation.
     *
     * @param list<array{type: string, value: string, depth: int}> $definition
     */
    private static function collationIn(array $definition): ?string
    {
        $collation = 'BINARY';
        $count = count($definition);

        for ($index = 0; $index < $count; ++$index) {
            $token = $definition[$index];
            if ($token['depth'] !== 0
                || $token['type'] !== self::TYPE_IDENTIFIER
                || strcasecmp($token['value'], 'COLLATE') !== 0
            ) {
                continue;
            }

            $argument = $definition[$index + 1] ?? null;
            if ($argument === null
                || $argument['depth'] !== 0
                || ($argument['type'] !== self::TYPE_IDENTIFIER && $argument['type'] !== self::TYPE_QUOTED)
            ) {
                // A clause we cannot read is unknown, never the default.
                return null;
            }

            $collation = strtoupper($argument['value']);
            ++$index;
        }

        return $collation;
    }

    /**
     * Split the outermost parenthesised list into one token list per definition.
     *
     * @param list<array{type: string, value: string, depth: int}> $tokens
     * @return list<list<array{type: string, value: string, depth: int}>>|null
     */
    private static function columnDefinitions(array $tokens): ?array
    {
        $open = null;
        foreach ($tokens as $index => $token) {
            if ($token['type'] === self::TYPE_PUNCTUATION && $token['value'] === '(' && $token['depth'] === 0) {
                $open = $index;
                break;
            }
        }
        if ($open === null) {
            return null;
        }

        $definitions = [];
        $current = [];
        $closed = false;

        for ($index = $open + 1, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token['depth'] === 0) {
                // Back to the outer level: this is the list's closing bracket.
                $closed = $token['type'] === self::TYPE_PUNCTUATION && $token['value'] === ')';
                break;
            }
            if ($token['depth'] === 1 && $token['type'] === self::TYPE_PUNCTUATION && $token['value'] === ',') {
                $definitions[] = $current;
                $current = [];
                continue;
            }
            // Re-base depth so a definition's own level is zero.
            $token['depth'] -= 1;
            $current[] = $token;
        }

        if (!$closed) {
            // Unbalanced text: nothing here can be asserted.
            return null;
        }
        if ($current !== []) {
            $definitions[] = $current;
        }

        return $definitions;
    }

    /**
     * Split schema text into tokens, preserving every boundary that matters.
     *
     * Whitespace and comments are **separators**: they end the token before them
     * and never merge the text either side. Quoted identifiers, string literals
     * and parenthesis nesting are preserved so a keyword is only ever recognised
     * as a keyword.
     *
     * @return list<array{type: string, value: string, depth: int}>
     */
    private static function tokenize(string $sql): array
    {
        $tokens = [];
        $depth = 0;
        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            $char = $sql[$offset];

            if (ctype_space($char)) {
                ++$offset;
                continue;
            }
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
            if ($char === "'") {
                [$value, $offset] = self::readDelimited($sql, $offset, "'", "'");
                $tokens[] = ['type' => self::TYPE_STRING, 'value' => $value, 'depth' => $depth];
                continue;
            }
            if ($char === '"' || $char === '`') {
                [$value, $offset] = self::readDelimited($sql, $offset, $char, $char);
                $tokens[] = ['type' => self::TYPE_QUOTED, 'value' => $value, 'depth' => $depth];
                continue;
            }
            if ($char === '[') {
                [$value, $offset] = self::readDelimited($sql, $offset, '[', ']');
                $tokens[] = ['type' => self::TYPE_QUOTED, 'value' => $value, 'depth' => $depth];
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A', $sql, $matches, 0, $offset) === 1) {
                $tokens[] = ['type' => self::TYPE_IDENTIFIER, 'value' => $matches[0], 'depth' => $depth];
                $offset += strlen($matches[0]);
                continue;
            }
            if ($char === '(') {
                $tokens[] = ['type' => self::TYPE_PUNCTUATION, 'value' => '(', 'depth' => $depth];
                ++$depth;
                ++$offset;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $tokens[] = ['type' => self::TYPE_PUNCTUATION, 'value' => ')', 'depth' => $depth];
                ++$offset;
                continue;
            }

            $tokens[] = ['type' => self::TYPE_PUNCTUATION, 'value' => $char, 'depth' => $depth];
            ++$offset;
        }

        return $tokens;
    }

    /**
     * Read a delimited run, honouring a doubled delimiter as an escape.
     *
     * @return array{0: string, 1: int}
     */
    private static function readDelimited(string $sql, int $offset, string $open, string $close): array
    {
        $length = strlen($sql);
        $value = '';
        ++$offset;

        while ($offset < $length) {
            if ($sql[$offset] === $close) {
                if ($open === $close && $offset + 1 < $length && $sql[$offset + 1] === $close) {
                    $value .= $close;
                    $offset += 2;
                    continue;
                }

                return [$value, $offset + 1];
            }
            $value .= $sql[$offset];
            ++$offset;
        }

        return [$value, $length];
    }
}
