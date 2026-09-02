<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/**
 * Structural description of one SQLite table, independent of DDL spelling.
 *
 * Two databases created by different code paths carry the same schema with
 * different `sqlite_master.sql` text: whitespace and line breaks, identifier
 * quoting, `CLOB` versus `TEXT`, an explicit `DEFAULT NULL`, a primary key
 * declared inline or at table level, constraint names, comments. Comparing
 * that text rejects schemas that are identical, so this description is built
 * from what SQLite itself reports (`table_xinfo`, `foreign_key_list`,
 * `index_list`, `index_xinfo`) and, for the semantics the pragmas do not
 * expose, from a canonical token stream of the DDL rather than its bytes:
 *
 * - CHECK expressions, generated-column expressions and storage, per-column
 *   COLLATE, ON CONFLICT clauses, AUTOINCREMENT, WITHOUT ROWID, STRICT, the
 *   rowid alias (`INTEGER PRIMARY KEY` exactly), and foreign-key
 *   deferrability come from the table DDL;
 * - expression columns and partial predicates come from the index DDL;
 * - triggers are compared as canonical token streams.
 *
 * Canonical tokens uppercase bare words, backtick- and bracket-quoted names,
 * keep single-quoted string literals and numbers verbatim, drop comments, and
 * separate every token with one space. A double-quoted token is unquoted and
 * uppercased only where SQLite admits nothing but an identifier: a table,
 * column, index, constraint, or collation name, or a column list. Inside an
 * expression (DEFAULT, CHECK, generated column, index expression or
 * predicate, trigger WHEN and body) SQLite may read `"A"` as a string literal,
 * so there the token is kept verbatim with its quotes and case. A quoting
 * difference inside an expression is therefore reported as a difference,
 * which is the fail-closed side of that ambiguity. Declared column types are
 * reduced to their affinity except in STRICT tables, where the declared type
 * is itself the constraint.
 *
 * @internal
 */
final class SqliteSchemaSignature
{
    private const string TOKEN_PATTERN = '/\s+|--[^\n]*|\/\*.*?\*\/|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|0[xX][0-9A-Fa-f]+|[0-9]+(?:\.[0-9]*)?(?:[eE][+-]?[0-9]+)?|\.[0-9]+(?:[eE][+-]?[0-9]+)?|[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*|<>|<=|>=|==|!=|\|\||<<|>>|./s';

    private const string NOT_DEFERRABLE = 'NOT DEFERRABLE';
    private const string DEFERRABLE_IMMEDIATE = 'DEFERRABLE INITIALLY IMMEDIATE';
    private const string DEFERRABLE_DEFERRED = 'DEFERRABLE INITIALLY DEFERRED';

    /** @return array<string, mixed> */
    public static function describe(\PDO $pdo, string $table): array
    {
        $quoted = self::quoteIdentifier($table);
        $tableSql = self::schemaSql($pdo, 'table', $table);
        if ($tableSql === null) {
            throw new \RuntimeException('Missing table schema SQL for ' . $table);
        }
        $ddl = self::parseTable(self::tokens($tableSql));
        $withoutRowid = $ddl['without_rowid'];
        $strict = $ddl['strict'];

        $columns = [];
        $primaryKey = [];
        foreach ($pdo->query("PRAGMA table_xinfo($quoted)")->fetchAll() as $column) {
            $name = strtoupper((string) $column['name']);
            $declared = (string) $column['type'];
            $residual = $ddl['columns'][$name] ?? [];
            $columns[] = [
                'name' => $name,
                'type' => $strict ? strtoupper(trim($declared)) : self::affinity($declared),
                'not_null' => (int) $column['notnull'] === 1,
                'default' => self::canonicalDefault($column['dflt_value']),
                'primary_key' => (int) $column['pk'],
                'hidden' => (int) $column['hidden'],
                'collate' => $residual['collate'] ?? null,
                'generated' => $residual['generated'] ?? null,
                'not_null_conflict' => $residual['not_null_conflict'] ?? null,
            ];
            if ((int) $column['pk'] > 0) {
                $primaryKey[(int) $column['pk']] = ['name' => $name, 'declared' => strtoupper(trim($declared))];
            }
        }
        ksort($primaryKey);
        $rowidAlias = !$withoutRowid
            && count($primaryKey) === 1
            && reset($primaryKey)['declared'] === 'INTEGER';

        $foreignKeys = self::foreignKeys($pdo, $quoted, $ddl['foreign_keys'], $table);

        $indexes = [];
        foreach ($pdo->query("PRAGMA index_list($quoted)")->fetchAll() as $index) {
            $name = (string) $index['name'];
            $origin = (string) $index['origin'];
            $entry = [
                'origin' => $origin,
                'name' => $origin === 'c' ? strtoupper($name) : null,
                'unique' => (int) $index['unique'] === 1,
                'partial' => (int) $index['partial'] === 1,
                'columns' => [],
                'expressions' => [],
                'where' => null,
            ];
            $isColumn = [];
            foreach ($pdo->query('PRAGMA index_xinfo(' . self::quoteIdentifier($name) . ')')->fetchAll() as $column) {
                if ((int) $column['key'] !== 1) {
                    continue;
                }
                $entry['columns'][] = [
                    'name' => $column['name'] === null ? null : strtoupper((string) $column['name']),
                    'desc' => (int) $column['desc'] === 1,
                    'collate' => strtoupper((string) $column['coll']),
                ];
                // cid >= 0 is a real column; -2 is an expression, which SQLite
                // also uses for a double-quoted token that names no column.
                $isColumn[] = (int) $column['cid'] >= 0;
            }
            $indexSql = self::schemaSql($pdo, 'index', $name);
            if ($indexSql !== null) {
                $parsed = self::parseIndex(self::tokens($indexSql), $isColumn);
                $entry['expressions'] = $parsed['items'];
                $entry['where'] = $parsed['where'];
            }
            $indexes[] = $entry;
        }
        usort($indexes, self::compareEncoded(...));

        $triggers = [];
        $statement = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY name");
        if ($statement === false) {
            throw new \RuntimeException('Could not prepare a SQLite statement.');
        }
        $statement->execute([$table]);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $triggers[] = self::canonicalTrigger(self::tokens($sql));
            }
        }
        sort($triggers, SORT_STRING);

        return [
            'columns' => $columns,
            'rowid_alias' => $rowidAlias,
            'autoincrement' => $ddl['autoincrement'],
            'without_rowid' => $withoutRowid,
            'strict' => $strict,
            'checks' => $ddl['checks'],
            'primary_key_conflict' => $ddl['primary_key_conflict'],
            'unique_constraints' => $ddl['unique_constraints'],
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'triggers' => $triggers,
        ];
    }

    /**
     * Dotted path of the first differing value between two descriptions, or
     * null when they are identical.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public static function firstDifference(array $left, array $right, string $path = ''): ?string
    {
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $here = ltrim($path . '.' . $key, '.');
            if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) {
                return $here;
            }
            if (is_array($left[$key]) && is_array($right[$key])) {
                $nested = self::firstDifference($left[$key], $right[$key], $here);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }
            if ($left[$key] !== $right[$key]) {
                return $here;
            }
        }

        return null;
    }

    /**
     * Foreign keys as the pragma reports them, each carrying the deferrability
     * state only the DDL knows. Pragma rows and parsed clauses are matched as
     * multisets on parent, columns, and both referential actions, so state
     * cannot migrate between two constraints that differ only in an action,
     * and a count mismatch fails closed rather than guessing.
     *
     * @param list<array{key:string,deferrable:string}> $parsed
     * @return list<array<string, mixed>>
     */
    private static function foreignKeys(\PDO $pdo, string $quoted, array $parsed, string $table): array
    {
        $rows = [];
        foreach ($pdo->query("PRAGMA foreign_key_list($quoted)")->fetchAll() as $row) {
            $id = (int) $row['id'];
            $rows[$id] ??= [
                'table' => strtoupper((string) $row['table']),
                'from' => [],
                'to' => [],
                'on_update' => strtoupper((string) $row['on_update']),
                'on_delete' => strtoupper((string) $row['on_delete']),
                'match' => strtoupper((string) $row['match']),
            ];
            $rows[$id]['from'][(int) $row['seq']] = strtoupper((string) $row['from']);
            $rows[$id]['to'][(int) $row['seq']] = $row['to'] === null ? null : strtoupper((string) $row['to']);
        }
        $states = [];
        foreach ($parsed as $clause) {
            $states[$clause['key']][] = $clause['deferrable'];
        }
        foreach ($states as &$group) {
            sort($group, SORT_STRING);
        }
        unset($group);

        $foreignKeys = [];
        $groups = [];
        foreach ($rows as $row) {
            ksort($row['from']);
            ksort($row['to']);
            $row['from'] = array_values($row['from']);
            $row['to'] = array_values($row['to']);
            $key = self::foreignKeyKey($row['table'], $row['from'], $row['to'], $row['on_update'], $row['on_delete']);
            $groups[$key][] = $row;
        }
        foreach ($groups as $key => $group) {
            $available = $states[$key] ?? [];
            if (count($available) !== count($group)) {
                throw new \RuntimeException(sprintf(
                    'Could not match %d foreign key clause(s) to %d reported constraint(s) on %s (%s).',
                    count($available),
                    count($group),
                    $table,
                    $key,
                ));
            }
            foreach ($group as $index => $row) {
                $row['deferrable'] = $available[$index];
                $foreignKeys[] = $row;
            }
        }
        usort($foreignKeys, self::compareEncoded(...));

        return $foreignKeys;
    }

    /**
     * The full structural key of one foreign key: parent, columns, and both
     * referential actions. Deferrability is the only fact left outside the
     * key, because it is the fact being attached. MATCH is not part of the key
     * on either side: SQLite parses it and ignores it, and the pragma always
     * reports NONE.
     *
     * @param list<string> $from
     * @param list<?string> $to
     */
    private static function foreignKeyKey(string $table, array $from, array $to, string $onUpdate, string $onDelete): string
    {
        return implode('|', [
            $table,
            implode(',', $from),
            implode(',', array_map(static fn(?string $column): string => $column ?? '', $to)),
            $onUpdate,
            $onDelete,
        ]);
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @return array{
     *     without_rowid:bool,
     *     strict:bool,
     *     autoincrement:bool,
     *     checks:list<string>,
     *     primary_key_conflict:?string,
     *     unique_constraints:list<array{columns:string,conflict:?string}>,
     *     columns:array<string,array<string,mixed>>,
     *     foreign_keys:list<array{key:string,deferrable:string}>
     * }
     */
    private static function parseTable(array $tokens): array
    {
        $result = [
            'without_rowid' => false,
            'strict' => false,
            'autoincrement' => false,
            'checks' => [],
            'primary_key_conflict' => null,
            'unique_constraints' => [],
            'columns' => [],
            'foreign_keys' => [],
        ];
        $open = null;
        foreach ($tokens as $index => $token) {
            if ($token[0] === 'p' && $token[1] === '(') {
                $open = $index;
                break;
            }
        }
        if ($open === null) {
            // A table defined from a SELECT carries no column list; nothing residual.
            return $result;
        }
        $close = self::matchingParen($tokens, $open);
        $tail = array_slice($tokens, $close + 1);
        $tailWords = array_values(array_map(static fn(array $token): string => $token[1], array_filter($tail, static fn(array $token): bool => $token[0] === 'w')));
        for ($i = 0, $count = count($tailWords); $i < $count; ++$i) {
            if ($tailWords[$i] === 'STRICT') {
                $result['strict'] = true;
            }
            if ($tailWords[$i] === 'WITHOUT' && ($tailWords[$i + 1] ?? null) === 'ROWID') {
                $result['without_rowid'] = true;
            }
        }

        foreach (self::splitTopLevel(array_slice($tokens, $open + 1, $close - $open - 1)) as $item) {
            if ($item === []) {
                continue;
            }
            if ($item[0][0] === 'w' && $item[0][1] === 'CONSTRAINT') {
                // A constraint name is an identity for DROP, not a semantic.
                $item = array_slice($item, 2);
            }
            $lead = $item[0][0] === 'w' ? $item[0][1] : '';
            $second = ($item[1][0] ?? '') === 'w' ? $item[1][1] : '';
            if ($lead === 'PRIMARY' && $second === 'KEY') {
                $result['primary_key_conflict'] = self::conflictClause($item);
                if (self::hasWord($item, 'AUTOINCREMENT')) {
                    $result['autoincrement'] = true;
                }
                continue;
            }
            if ($lead === 'UNIQUE') {
                $group = self::groupAfter($item, 0);
                $result['unique_constraints'][] = [
                    'columns' => implode(',', $group === null ? [] : self::identifierList($group)),
                    'conflict' => self::conflictClause($item),
                ];
                continue;
            }
            if ($lead === 'CHECK') {
                $group = self::groupAfter($item, 0);
                if ($group !== null) {
                    $result['checks'][] = self::canonical($group);
                }
                continue;
            }
            if ($lead === 'FOREIGN' && $second === 'KEY') {
                $group = self::groupAfter($item, 1);
                $from = $group === null ? [] : self::identifierList($group);
                $reference = self::parseReferences($item, $from);
                if ($reference !== null) {
                    $result['foreign_keys'][] = $reference;
                }
                continue;
            }

            $column = self::parseColumn($item);
            $result['columns'][$column['name']] = $column['residual'];
            foreach ($column['checks'] as $check) {
                $result['checks'][] = $check;
            }
            if ($column['autoincrement']) {
                $result['autoincrement'] = true;
            }
            if ($column['primary_key_conflict'] !== null) {
                $result['primary_key_conflict'] = $column['primary_key_conflict'];
            }
            if ($column['unique'] !== null) {
                $result['unique_constraints'][] = $column['unique'];
            }
            if ($column['reference'] !== null) {
                $result['foreign_keys'][] = $column['reference'];
            }
        }
        sort($result['checks'], SORT_STRING);
        usort($result['unique_constraints'], self::compareEncoded(...));

        return $result;
    }

    /**
     * @param list<array{0:string,1:string}> $item
     * @return array{
     *     name:string,
     *     residual:array<string,mixed>,
     *     checks:list<string>,
     *     autoincrement:bool,
     *     primary_key_conflict:?string,
     *     unique:?array{columns:string,conflict:?string},
     *     reference:?array{key:string,deferrable:string}
     * }
     */
    private static function parseColumn(array $item): array
    {
        $name = self::identifierOf($item[0]);
        if ($name === null) {
            throw new \RuntimeException('Column definition does not start with an identifier.');
        }
        $residual = [];
        $checks = [];
        $autoincrement = false;
        $primaryKeyConflict = null;
        $unique = null;
        $count = count($item);
        $depth = 0;
        for ($i = 1; $i < $count; ++$i) {
            if ($item[$i][0] === 'p') {
                $depth += match ($item[$i][1]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                continue;
            }
            // Only top-level words are column constraints; words inside a
            // parenthesised expression (CAST(x AS INT) in a CHECK) are not.
            if ($item[$i][0] !== 'w' || $depth > 0) {
                continue;
            }
            $word = $item[$i][1];
            $next = ($item[$i + 1][0] ?? '') === 'w' ? $item[$i + 1][1] : '';
            if ($word === 'COLLATE') {
                $collation = self::identifierOf($item[$i + 1] ?? ['p', '']);
                if ($collation !== null) {
                    $residual['collate'] = $collation;
                }
            } elseif ($word === 'CHECK') {
                $group = self::groupAfter($item, $i);
                if ($group !== null) {
                    $checks[] = self::canonical($group);
                }
            } elseif ($word === 'NOT' && $next === 'NULL') {
                $conflict = self::conflictWordAt($item, $i + 2);
                if ($conflict !== null) {
                    $residual['not_null_conflict'] = $conflict;
                }
            } elseif ($word === 'PRIMARY' && $next === 'KEY') {
                $primaryKeyConflict = self::conflictClause(array_slice($item, $i));
            } elseif ($word === 'UNIQUE') {
                // Same shape as a table-level UNIQUE (col): both create one
                // autoindex, so neither spelling may look like a different schema.
                $unique = ['columns' => $name, 'conflict' => self::conflictWordAt($item, $i + 1)];
            } elseif ($word === 'AUTOINCREMENT') {
                $autoincrement = true;
            } elseif ($word === 'AS' || ($word === 'GENERATED' && $next === 'ALWAYS')) {
                $group = self::groupAfter($item, $i);
                if ($group !== null) {
                    $stored = false;
                    for ($j = $i; $j < $count; ++$j) {
                        if ($item[$j][0] === 'w' && $item[$j][1] === 'STORED') {
                            $stored = true;
                        }
                    }
                    $residual['generated'] = ['expression' => self::canonical($group), 'stored' => $stored];
                }
            }
        }

        return [
            'name' => $name,
            'residual' => $residual,
            'checks' => $checks,
            'autoincrement' => $autoincrement,
            'primary_key_conflict' => $primaryKeyConflict,
            'unique' => $unique,
            'reference' => self::parseReferences($item, [$name]),
        ];
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @param list<bool> $isColumn Per indexed item, whether index_xinfo
     *        reports a real column (cid >= 0) rather than an expression.
     * @return array{items:list<string>,where:?string}
     */
    private static function parseIndex(array $tokens, array $isColumn): array
    {
        $on = null;
        foreach ($tokens as $index => $token) {
            if ($token[0] === 'w' && $token[1] === 'ON') {
                $on = $index;
                break;
            }
        }
        if ($on === null) {
            return ['items' => [], 'where' => null];
        }
        $open = null;
        for ($i = $on, $count = count($tokens); $i < $count; ++$i) {
            if ($tokens[$i][0] === 'p' && $tokens[$i][1] === '(') {
                $open = $i;
                break;
            }
        }
        if ($open === null) {
            return ['items' => [], 'where' => null];
        }
        $close = self::matchingParen($tokens, $open);
        $items = [];
        foreach (self::splitTopLevel(array_slice($tokens, $open + 1, $close - $open - 1)) as $position => $item) {
            $items[] = self::canonicalIndexItem($item, $isColumn[$position] ?? false);
        }
        $where = null;
        $rest = array_slice($tokens, $close + 1);
        if (($rest[0][0] ?? '') === 'w' && $rest[0][1] === 'WHERE') {
            $where = self::canonical(array_slice($rest, 1));
        }

        return ['items' => $items, 'where' => $where];
    }

    /**
     * An indexed item is identifier context only when SQLite itself resolved
     * it to a column (`"name" COLLATE x DESC`). SQLite decides that, not the
     * token shape: `("A")` with no column A indexes the string literal, and
     * index_xinfo reports it as an expression, so it stays verbatim.
     *
     * @param list<array{0:string,1:string}> $item
     */
    private static function canonicalIndexItem(array $item, bool $isColumn): string
    {
        $head = self::identifierOf($item[0] ?? ['p', '']);
        if (!$isColumn || $head === null) {
            return self::canonical($item);
        }
        $parts = [$head];
        $rest = array_slice($item, 1);
        for ($i = 0, $count = count($rest); $i < $count; ++$i) {
            $collation = $rest[$i][0] === 'w' && $rest[$i][1] === 'COLLATE'
                ? self::identifierOf($rest[$i + 1] ?? ['p', ''])
                : null;
            if ($collation !== null) {
                $parts[] = 'COLLATE';
                $parts[] = $collation;
                ++$i;
                continue;
            }
            $parts[] = $rest[$i][1];
        }

        return implode(' ', $parts);
    }

    /**
     * Trigger name, event columns, and the target table are identifiers; the
     * WHEN clause and the body are expressions and statements, where a
     * double-quoted token may be a string literal, so they stay verbatim.
     *
     * @param list<array{0:string,1:string}> $tokens
     */
    private static function canonicalTrigger(array $tokens): string
    {
        $parts = [];
        $header = true;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($header) {
                $identifier = self::identifierOf($token);
                $parts[] = $identifier ?? $token[1];
                if ($token[0] === 'w' && $token[1] === 'ON') {
                    $table = self::identifierOf($tokens[$i + 1] ?? ['p', '']);
                    if ($table !== null) {
                        $parts[] = $table;
                        ++$i;
                    }
                    $header = false;
                }
                continue;
            }
            $parts[] = $token[1];
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<array{0:string,1:string}> $item
     * @param list<string> $from
     * @return ?array{key:string,deferrable:string}
     */
    private static function parseReferences(array $item, array $from): ?array
    {
        $count = count($item);
        $depth = 0;
        for ($i = 0; $i < $count; ++$i) {
            if ($item[$i][0] === 'p') {
                $depth += match ($item[$i][1]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                continue;
            }
            if ($depth > 0 || $item[$i][0] !== 'w' || $item[$i][1] !== 'REFERENCES') {
                continue;
            }
            $parent = self::identifierOf($item[$i + 1] ?? ['p', '']);
            if ($parent === null) {
                continue;
            }
            $to = [];
            $after = $i + 2;
            if (($item[$after][0] ?? '') === 'p' && $item[$after][1] === '(') {
                $close = self::matchingParen($item, $after);
                $to = self::identifierList(array_slice($item, $after + 1, $close - $after - 1));
                $after = $close + 1;
            }
            if ($to === []) {
                $to = array_fill(0, count($from), null);
            }
            $deferrable = false;
            $initiallyDeferred = false;
            $onUpdate = 'NO ACTION';
            $onDelete = 'NO ACTION';
            for ($j = $after; $j < $count; ++$j) {
                if ($item[$j][0] !== 'w') {
                    continue;
                }
                $word = $item[$j][1];
                $next = ($item[$j + 1][0] ?? '') === 'w' ? $item[$j + 1][1] : '';
                if ($word === 'ON' && ($next === 'UPDATE' || $next === 'DELETE')) {
                    // Actions spelled the way the pragma reports them.
                    $first = ($item[$j + 2][0] ?? '') === 'w' ? $item[$j + 2][1] : '';
                    $second = ($item[$j + 3][0] ?? '') === 'w' ? $item[$j + 3][1] : '';
                    $action = match ($first) {
                        'SET' => 'SET ' . $second,
                        'NO' => 'NO ACTION',
                        default => $first,
                    };
                    if ($next === 'UPDATE') {
                        $onUpdate = $action;
                    } else {
                        $onDelete = $action;
                    }
                    $j += $first === 'SET' || $first === 'NO' ? 3 : 2;
                    continue;
                }
                if ($word === 'NOT' && $next === 'DEFERRABLE') {
                    $deferrable = false;
                    ++$j;
                    continue;
                }
                if ($word === 'DEFERRABLE') {
                    $deferrable = true;
                }
                if ($word === 'INITIALLY' && $next === 'DEFERRED') {
                    $initiallyDeferred = true;
                }
            }
            // SQLite: only DEFERRABLE INITIALLY DEFERRED defers; a bare
            // DEFERRABLE is INITIALLY IMMEDIATE; NOT DEFERRABLE ignores INITIALLY.
            $state = match (true) {
                !$deferrable => self::NOT_DEFERRABLE,
                $initiallyDeferred => self::DEFERRABLE_DEFERRED,
                default => self::DEFERRABLE_IMMEDIATE,
            };

            return ['key' => self::foreignKeyKey($parent, $from, $to, $onUpdate, $onDelete), 'deferrable' => $state];
        }

        return null;
    }

    /** @param list<array{0:string,1:string}> $item */
    private static function conflictClause(array $item): ?string
    {
        $count = count($item);
        for ($i = 0; $i < $count; ++$i) {
            if ($item[$i][0] === 'w' && $item[$i][1] === 'ON' && ($item[$i + 1][1] ?? '') === 'CONFLICT') {
                return self::conflictWordAt($item, $i);
            }
        }

        return null;
    }

    /** @param list<array{0:string,1:string}> $item */
    private static function conflictWordAt(array $item, int $index): ?string
    {
        if (($item[$index][1] ?? '') === 'ON' && ($item[$index + 1][1] ?? '') === 'CONFLICT' && ($item[$index + 2][0] ?? '') === 'w') {
            return $item[$index + 2][1];
        }

        return null;
    }

    /** @param list<array{0:string,1:string}> $tokens */
    private static function hasWord(array $tokens, string $word): bool
    {
        foreach ($tokens as $token) {
            if ($token[0] === 'w' && $token[1] === $word) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tokens inside the first parenthesised group at or after $from.
     *
     * @param list<array{0:string,1:string}> $tokens
     * @return ?list<array{0:string,1:string}>
     */
    private static function groupAfter(array $tokens, int $from): ?array
    {
        for ($i = $from, $count = count($tokens); $i < $count; ++$i) {
            if ($tokens[$i][0] === 'p' && $tokens[$i][1] === '(') {
                $close = self::matchingParen($tokens, $i);

                return array_slice($tokens, $i + 1, $close - $i - 1);
            }
        }

        return null;
    }

    /**
     * Column names from a column list: identifier context, so quoting and
     * case never matter here.
     *
     * @param list<array{0:string,1:string}> $tokens
     * @return list<string>
     */
    private static function identifierList(array $tokens): array
    {
        $names = [];
        foreach (self::splitTopLevel($tokens) as $item) {
            $name = self::identifierOf($item[0] ?? ['p', '']);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The identifier a token denotes in identifier context, or null when the
     * token cannot be one (a string literal, number, or punctuation).
     *
     * @param array{0:string,1:string} $token
     */
    private static function identifierOf(array $token): ?string
    {
        return match ($token[0]) {
            'w' => $token[1],
            'q' => strtoupper(str_replace('""', '"', substr($token[1], 1, -1))),
            default => null,
        };
    }

    /** @param list<array{0:string,1:string}> $tokens */
    private static function matchingParen(array $tokens, int $open): int
    {
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; ++$i) {
            if ($tokens[$i][0] !== 'p') {
                continue;
            }
            if ($tokens[$i][1] === '(') {
                ++$depth;
            } elseif ($tokens[$i][1] === ')') {
                --$depth;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('Unbalanced parentheses in SQLite schema SQL.');
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @return list<list<array{0:string,1:string}>>
     */
    private static function splitTopLevel(array $tokens): array
    {
        $items = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token[0] === 'p') {
                if ($token[1] === '(') {
                    ++$depth;
                } elseif ($token[1] === ')') {
                    --$depth;
                } elseif ($token[1] === ',' && $depth === 0) {
                    $items[] = $current;
                    $current = [];
                    continue;
                }
            }
            $current[] = $token;
        }
        $items[] = $current;

        return array_values(array_filter($items, static fn(array $item): bool => $item !== []));
    }

    /**
     * Tokens as [kind, value]: w = bare word, backtick- or bracket-quoted
     * name (uppercased, unquoted); q = double-quoted token kept verbatim,
     * because SQLite may read it as an identifier or as a string literal
     * depending on context; s = single-quoted string literal (verbatim);
     * n = number (verbatim); p = punctuation.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function tokens(string $sql): array
    {
        preg_match_all(self::TOKEN_PATTERN, $sql, $matches);
        $tokens = [];
        foreach ($matches[0] as $raw) {
            if ($raw === '' || ctype_space($raw) || str_starts_with($raw, '--') || str_starts_with($raw, '/*')) {
                continue;
            }
            $first = $raw[0];
            if ($first === "'") {
                $tokens[] = ['s', $raw];
            } elseif ($first === '"') {
                $tokens[] = ['q', $raw];
            } elseif ($first === '`' || $first === '[') {
                $inner = substr($raw, 1, -1);
                $tokens[] = ['w', strtoupper($first === '`' ? str_replace('``', '`', $inner) : $inner)];
            } elseif (ctype_digit($first) || $first === '.' && strlen($raw) > 1 && ctype_digit($raw[1])) {
                $tokens[] = ['n', $raw];
            } elseif (preg_match('/^[A-Za-z_\x80-\xff]/', $raw) === 1) {
                $tokens[] = ['w', strtoupper($raw)];
            } else {
                $tokens[] = ['p', $raw];
            }
        }

        return $tokens;
    }

    /**
     * Expression context: every token verbatim, one space apart.
     *
     * @param list<array{0:string,1:string}> $tokens
     */
    private static function canonical(array $tokens): string
    {
        return implode(' ', array_map(static fn(array $token): string => $token[1], $tokens));
    }

    private static function canonicalDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $tokens = self::tokens((string) $default);
        if ($tokens === [] || (count($tokens) === 1 && $tokens[0][0] === 'w' && $tokens[0][1] === 'NULL')) {
            return null;
        }
        // `DEFAULT ('x')` and `DEFAULT 'x'` are the same literal default.
        if (count($tokens) === 3 && $tokens[0] === ['p', '('] && $tokens[2] === ['p', ')'] && $tokens[1][0] !== 'p') {
            $tokens = [$tokens[1]];
        }

        return self::canonical($tokens);
    }

    private static function affinity(string $declared): string
    {
        $type = strtoupper($declared);
        if (str_contains($type, 'INT')) {
            return 'INTEGER';
        }
        if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
            return 'TEXT';
        }
        if (trim($type) === '' || str_contains($type, 'BLOB')) {
            return 'BLOB';
        }
        if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
            return 'REAL';
        }

        return 'NUMERIC';
    }

    private static function schemaSql(\PDO $pdo, string $type, string $name): ?string
    {
        $statement = $pdo->prepare('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?');
        if ($statement === false) {
            throw new \RuntimeException('Could not prepare a SQLite statement.');
        }
        $statement->execute([$type, $name]);
        $sql = $statement->fetchColumn();

        return is_string($sql) && trim($sql) !== '' ? $sql : null;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function compareEncoded(array $left, array $right): int
    {
        return strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR));
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
