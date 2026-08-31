<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * Reads facts about a table that SQLite exposes only through its stored schema.
 *
 * `PRAGMA table_info` reports name, type, nullability and default, but **not**
 * collation, a `NOT NULL` conflict policy, or a `CHECK` expression. Each of
 * those changes what the column does, so a precondition check that ignores them
 * can accept a column that behaves differently from the authored one. The stored
 * definition is the only read-only source for them.
 *
 * **This class reads schema text. It issues none.**
 *
 * Interpretation is token-preserving. An earlier character-flattening approach
 * was wrong in kind, not merely in detail: discarding comments concatenated the
 * tokens either side of them, and scanning for a keyword by position matched it
 * inside longer identifiers. Both produced a *confident* answer that disagreed
 * with SQLite, which is the one failure this class must never have.
 *
 * {@see collationOf()} has three outcomes, never two:
 *
 * - a column whose definition carries a `COLLATE` clause → that collation
 *   (the **last** clause, which is the one SQLite applies);
 * - a column carrying none → `BINARY`, authoritative because it is SQLite's
 *   documented default rather than a guess;
 * - anything unresolved — table absent, schema text unreadable, column not
 *   found, a `COLLATE` clause whose argument is not a name in any of the
 *   spellings SQLite accepts → **unknown**, reported as `null`.
 *
 * Callers must treat unknown as "cannot establish equivalence" and fail closed.
 * Collapsing unknown to `BINARY` would silently accept a mismatched index.
 *
 * {@see plainColumnDivergences()} answers the wider question for `AddColumn`,
 * and folds unknown into its result rather than beside it: every reason it
 * returns refuses, whether it is a divergence it read or a construct it could
 * not. An empty list is therefore a positive statement — the stored definition
 * establishes that this column carries nothing outside the authored vocabulary.
 *
 * @see docs/change-records/FW-2701.md
 */
final readonly class SqliteTableDefinition
{
    /** Leading bare keywords that introduce a table constraint, not a column. */
    private const CONSTRAINT_KEYWORDS = [
        'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN',
    ];

    /**
     * The only conflict policy a bare `NOT NULL` — all the compiler emits —
     * means. The other four are observably different columns: `FAIL` keeps the
     * rows a failing statement already wrote, `ROLLBACK` discards the enclosing
     * transaction, and `IGNORE` / `REPLACE` do not raise at all.
     */
    private const COMPILER_CONFLICT_POLICY = 'ABORT';

    /**
     * Token types SQLite accepts where a *column name* is expected.
     *
     * A string literal is legal in an identifier position, so `'value' INTEGER`
     * declares an ordinary column named `value`. Reading only the bare and
     * delimited spellings reported such a column as unknown, which refuses a
     * migration that is in fact valid — the same class of wrong answer the
     * collation reader corrected for `COLLATE 'NOCASE'`.
     */
    private const COLUMN_NAME_TYPES = [
        self::TYPE_IDENTIFIER, self::TYPE_QUOTED, self::TYPE_STRING,
    ];

    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const TYPE_IDENTIFIER = 'identifier';
    private const TYPE_QUOTED = 'quoted';
    private const TYPE_STRING = 'string';
    private const TYPE_PUNCTUATION = 'punctuation';

    /**
     * Token types SQLite accepts where a collation name is expected.
     *
     * All four spellings name the same collating sequence — `COLLATE NOCASE`,
     * `COLLATE "NOCASE"`, `` COLLATE `NOCASE` ``, `COLLATE [NOCASE]` and
     * `COLLATE 'NOCASE'` — because SQLite accepts a string literal wherever an
     * identifier is expected. Reading only the bare and quoted-identifier forms
     * reported a legitimately `NOCASE` column as unknown, and unknown makes the
     * caller refuse a migration whose index is in fact equivalent. That is
     * fail-closed rather than dangerous, but it is still a wrong answer about
     * valid DDL.
     */
    private const COLLATION_NAME_TYPES = [
        self::TYPE_IDENTIFIER, self::TYPE_QUOTED, self::TYPE_STRING,
    ];

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

        $target = self::definitionOf($definitions, $column);

        return $target === null ? null : self::collationIn($target);
    }

    /**
     * Every reason this column is not the plain column an `AddColumn` declares.
     *
     * The authored vocabulary is type, nullability and default; SQLite's
     * `column-constraint` and `table-constraint` productions are finite, so the
     * properties outside it are a closed list. `PRIMARY KEY`, `UNIQUE`,
     * `REFERENCES` and generated columns have pragmas and are the caller's to
     * decide. What only the stored text can answer is here: the effective
     * collation, a `NOT NULL` conflict policy, and whether any `CHECK` on the
     * table can read this column.
     *
     * An empty list means the definition **establishes** that none of those
     * applies. A construct this reader cannot interpret is returned as a reason
     * too, so unknown refuses rather than passing silently.
     *
     * @return list<string>
     */
    public function plainColumnDivergences(string $column): array
    {
        $tokens = self::tokenize($this->sql);
        if (!self::isOrdinaryCreateTable($tokens)) {
            // A virtual table's parenthesised list is module arguments, not
            // column definitions, and its ordinary columns still report
            // `hidden = 0`. Nothing here can be asserted about them.
            return ['the stored definition is not an ordinary table declaration'];
        }

        $definitions = self::columnDefinitions($tokens);
        if ($definitions === null) {
            return ['the stored definition could not be split into column definitions'];
        }

        $divergences = [];
        $checks = [];
        /** @var array<string, array<string, true>> $generated */
        $generated = [];

        foreach ($definitions as $definition) {
            foreach (self::clauseGroups($definition, 'CHECK') as $group) {
                if ($group === null) {
                    $divergences[] = 'a CHECK clause could not be read';
                    continue;
                }
                $checks[] = self::candidateNames($group);
            }

            $name = self::definitionName($definition);
            if ($name === null) {
                continue;
            }
            foreach (self::clauseGroups($definition, 'AS') as $group) {
                if ($group === null) {
                    $divergences[] = 'a generated-column expression could not be read';
                    continue;
                }
                $generated[strtolower($name)] = self::candidateNames($group);
            }
        }

        $target = self::definitionOf($definitions, $column);
        if ($target === null) {
            $divergences[] = 'the column is not present in the stored definition';

            return self::unique($divergences);
        }

        $collation = self::collationIn($target);
        if ($collation === null) {
            $divergences[] = 'a COLLATE clause argument could not be read';
        } elseif ($collation !== 'BINARY') {
            $divergences[] = sprintf('the column declares COLLATE %s', $collation);
        }

        $policy = self::conflictPolicyIn($target);
        if ($policy === self::CONFLICT_POLICY_UNREADABLE) {
            $divergences[] = 'an ON CONFLICT policy could not be read';
        } elseif ($policy !== null && $policy !== self::COMPILER_CONFLICT_POLICY) {
            $divergences[] = sprintf(
                "the column declares ON CONFLICT %s, not the compiler's ABORT",
                $policy,
            );
        }

        $needle = strtolower($column);
        foreach ($checks as $candidates) {
            if (isset(self::throughGeneratedColumns($candidates, $generated)[$needle])) {
                $divergences[] = 'a CHECK constraint can read the column';
                break;
            }
        }

        return self::unique($divergences);
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
                || !in_array($argument['type'], self::COLLATION_NAME_TYPES, true)
            ) {
                // A clause we cannot read is unknown, never the default.
                return null;
            }

            $collation = strtoupper($argument['value']);
            ++$index;
        }

        return $collation;
    }

    /** Sentinel distinguishing "an ON CONFLICT we could not read" from "none". */
    private const CONFLICT_POLICY_UNREADABLE = '?';

    /**
     * The `NOT NULL` conflict policy this definition applies, `null` when it
     * declares no `NOT NULL` at all, or {@see CONFLICT_POLICY_UNREADABLE} when a
     * clause is present but its policy is not a readable name.
     *
     * The policy is **attributed**, not merely found. A definition may carry
     * several constraints, and SQLite applies the last `NOT NULL` — a later one
     * naming no policy restores `ABORT`. A conflict clause on a bare `NULL`
     * constraint is inert, and one on `PRIMARY KEY` or `UNIQUE` belongs to a
     * constraint the caller has already refused from its pragma. Reading "any
     * `ON CONFLICT` that is not `ABORT`" instead refuses
     * `NOT NULL ON CONFLICT ABORT NULL ON CONFLICT IGNORE`, which SQLite treats
     * exactly like a bare `NOT NULL`.
     *
     * @param list<array{type: string, value: string, depth: int}> $definition
     */
    private static function conflictPolicyIn(array $definition): ?string
    {
        $applied = null;
        $count = count($definition);

        for ($index = 0; $index < $count; ++$index) {
            if (!self::isKeyword($definition[$index], 'NOT')
                || !self::isKeyword($definition[$index + 1] ?? null, 'NULL')
            ) {
                continue;
            }
            ++$index;

            // This clause's own default, superseded only by its own ON CONFLICT.
            $applied = self::COMPILER_CONFLICT_POLICY;
            if (!self::isKeyword($definition[$index + 1] ?? null, 'ON')
                || !self::isKeyword($definition[$index + 2] ?? null, 'CONFLICT')
            ) {
                continue;
            }

            $policy = $definition[$index + 3] ?? null;
            if ($policy === null
                || $policy['depth'] !== 0
                || !in_array($policy['type'], self::COLUMN_NAME_TYPES, true)
            ) {
                return self::CONFLICT_POLICY_UNREADABLE;
            }

            $applied = strtoupper($policy['value']);
            $index += 3;
        }

        return $applied;
    }

    /**
     * The parenthesised groups introduced by a bare keyword at this
     * definition's own level.
     *
     * A `null` entry marks a keyword found without a readable group — a
     * `CHECK` or `AS` with nothing after it — so the caller reports it as
     * unknown instead of skipping it.
     *
     * @param  list<array{type: string, value: string, depth: int}> $definition
     * @return list<list<array{type: string, value: string, depth: int}>|null>
     */
    private static function clauseGroups(array $definition, string $keyword): array
    {
        $groups = [];
        $count = count($definition);

        for ($index = 0; $index < $count; ++$index) {
            if (!self::isKeyword($definition[$index], $keyword)) {
                continue;
            }

            $open = $definition[$index + 1] ?? null;
            if ($open === null
                || $open['depth'] !== 0
                || $open['type'] !== self::TYPE_PUNCTUATION
                || $open['value'] !== '('
            ) {
                $groups[] = null;
                continue;
            }

            // Everything until depth returns to this definition's own level,
            // which is where the tokenizer emits the matching close bracket.
            $group = [];
            for ($inner = $index + 2; $inner < $count; ++$inner) {
                if ($definition[$inner]['depth'] === 0) {
                    break;
                }
                $group[] = $definition[$inner];
            }
            $groups[] = $group;
            $index = $index + 1 + count($group);
        }

        return $groups;
    }

    /**
     * Every name an expression may read, over-approximated on purpose.
     *
     * SQLite names a column only as `[[schema.]table.]column`, so a candidate is
     * a bare identifier, a delimited identifier, or a single-quoted token in an
     * identifier position — one adjacent to a `.`, which is the only context
     * where SQLite reads a string literal as an identifier. A standalone
     * `'value'` is a literal and is correctly not a candidate.
     *
     * The approximation is one-sided by design. It can name more than the
     * expression reads — a function name, the `e10` of a numeric literal — which
     * costs a false refusal. It cannot name fewer, because every reference
     * spelling SQLite offers is admitted, and that is what makes the caller's
     * empty result a proof rather than a hope.
     *
     * @param  list<array{type: string, value: string, depth: int}> $tokens
     * @return array<string, true>
     */
    private static function candidateNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            $candidate = match ($token['type']) {
                self::TYPE_IDENTIFIER, self::TYPE_QUOTED => true,
                self::TYPE_STRING => self::isDot($tokens[$index - 1] ?? null)
                    || self::isDot($tokens[$index + 1] ?? null),
                default => false,
            };
            if ($candidate) {
                $names[strtolower($token['value'])] = true;
            }
        }

        return $names;
    }

    /**
     * Close a candidate set over the table's generated columns.
     *
     * A generated column is an alias for its own expression, so a CHECK naming
     * only `derived` reads whatever `derived AS (…)` reads — transitively, and
     * with a cycle guard because the closure is computed from text rather than
     * from a schema SQLite has already validated.
     *
     * @param  array<string, true>                $names
     * @param  array<string, array<string, true>> $generated
     * @return array<string, true>
     */
    private static function throughGeneratedColumns(array $names, array $generated): array
    {
        $reached = [];
        $pending = array_keys($names);

        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($reached[$name])) {
                continue;
            }
            $reached[$name] = true;
            foreach (array_keys($generated[$name] ?? []) as $dependency) {
                $pending[] = $dependency;
            }
        }

        return $reached;
    }

    /**
     * Whether the statement declares an ordinary table whose parenthesised
     * list is a column list.
     *
     * A virtual table names module arguments in those parentheses, and a view
     * names a query, so neither can be read as column definitions.
     *
     * The header is read **by position**, because everything after the object
     * name is the author's to choose: a table *named* `virtual` is an ordinary
     * one, and searching the words before the column list for `VIRTUAL` refuses
     * it.
     *
     * @param list<array{type: string, value: string, depth: int}> $tokens
     */
    private static function isOrdinaryCreateTable(array $tokens): bool
    {
        if (!self::isKeyword($tokens[0] ?? null, 'CREATE')) {
            return false;
        }

        $index = 1;
        if (self::isKeyword($tokens[$index] ?? null, 'TEMP')
            || self::isKeyword($tokens[$index] ?? null, 'TEMPORARY')
        ) {
            ++$index;
        }

        return self::isKeyword($tokens[$index] ?? null, 'TABLE');
    }

    /**
     * The definition declaring this column, or null when none does.
     *
     * @param  list<list<array{type: string, value: string, depth: int}>> $definitions
     * @return list<array{type: string, value: string, depth: int}>|null
     */
    private static function definitionOf(array $definitions, string $column): ?array
    {
        foreach ($definitions as $definition) {
            $name = self::definitionName($definition);
            if ($name !== null && strcasecmp($name, $column) === 0) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The column name a definition declares, or null when it is a table
     * constraint rather than a column.
     *
     * @param list<array{type: string, value: string, depth: int}> $definition
     */
    private static function definitionName(array $definition): ?string
    {
        $first = $definition[0] ?? null;
        if ($first === null || !in_array($first['type'], self::COLUMN_NAME_TYPES, true)) {
            return null;
        }
        // A *bare* leading keyword introduces a table constraint. A delimited or
        // string-quoted one is unambiguously a column name — `"check"` is legal.
        if ($first['type'] === self::TYPE_IDENTIFIER
            && in_array(strtoupper($first['value']), self::CONSTRAINT_KEYWORDS, true)
        ) {
            return null;
        }

        return $first['value'];
    }

    /** @param array{type: string, value: string, depth: int}|null $token */
    private static function isKeyword(?array $token, string $keyword): bool
    {
        return $token !== null
            && $token['depth'] === 0
            && $token['type'] === self::TYPE_IDENTIFIER
            && strcasecmp($token['value'], $keyword) === 0;
    }

    /** @param array{type: string, value: string, depth: int}|null $token */
    private static function isDot(?array $token): bool
    {
        return $token !== null
            && $token['type'] === self::TYPE_PUNCTUATION
            && $token['value'] === '.';
    }

    /**
     * @param  list<string> $reasons
     * @return list<string>
     */
    private static function unique(array $reasons): array
    {
        return array_values(array_unique($reasons));
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
            // SQLite's lexer skips a UTF-8 byte-order mark where a token would
            // start, treating it as whitespace. This check sits at the top of
            // the loop, which IS a token-start position, so the same bytes
            // occurring *inside* a token are never reached here: the identifier
            // match below consumes them as ordinary identifier characters, which
            // is also what SQLite does. Stripping them globally would be wrong —
            // `COLLATE<BOM>NOCASE` is a single identifier to SQLite and carries
            // no collation clause at all.
            if (substr($sql, $offset, 3) === self::BYTE_ORDER_MARK) {
                $offset += 3;
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
            // SQLite's own identifier rule (sqlite3IsIdChar) treats every byte
            // >= 0x80 as an identifier character, so a non-ASCII byte does NOT
            // end an identifier. Matching only ASCII split identifiers early and
            // could fabricate a standalone COLLATE token where SQLite sees a
            // multi-word type name — a confident answer that disagreed with the
            // database, and one that failed open.
            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/A', $sql, $matches, 0, $offset) === 1) {
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
