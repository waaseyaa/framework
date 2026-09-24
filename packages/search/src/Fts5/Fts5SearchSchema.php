<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Doctrine\DBAL\Connection;

/**
 * The one owner of the FTS5 search projection's schema (FW-SEARCH-PERSIST-01).
 *
 * It runs in exactly two places. On the authoritative database it runs only
 * inside the `waaseyaa/search` package migration, under the coordinator. On a
 * dedicated `search.database` file, which is outside schema authority, it runs
 * only from {@see Fts5SearchIndexer::removeAll()}, the `search:reindex`
 * rebuild. Serving paths never call it.
 *
 * - An absent object is created. The DDL text is the text the pre-migration
 *   runtime code used, so a migrated and a runtime-created projection have the
 *   same logical schema fingerprint.
 * - An object with the expected definition is adopted in place with its rows.
 * - A `search_index` with the retired `porter unicode61` tokenizer (framework
 *   versions before 0.1.0-alpha.263) is rebuilt with the current tokenizer and
 *   keeps every row.
 * - Anything else is refused with `[SEARCH-DB001]` before any change.
 *
 * @internal
 */
final class Fts5SearchSchema
{
    public const string INDEX_TABLE = 'search_index';
    public const string METADATA_TABLE = 'search_metadata';

    // Indented exactly as the runtime code's heredocs were, so the stored SQL
    // (SQLite trims only the first line's leading spaces) is byte-identical.
    private const string INDEX_DDL = <<<'SQL'
            CREATE VIRTUAL TABLE search_index USING fts5(
                document_id UNINDEXED,
                title,
                body,
                tokenize="unicode61 remove_diacritics 0 tokenchars '''’ʼ'"
            )
        SQL;

    private const string LEGACY_PORTER_INDEX_DDL = <<<'SQL'
            CREATE VIRTUAL TABLE search_index USING fts5(
                document_id UNINDEXED,
                title,
                body,
                tokenize='porter unicode61'
            )
        SQL;

    private const string METADATA_DDL = <<<'SQL'
            CREATE TABLE search_metadata (
                document_id TEXT PRIMARY KEY,
                entity_type TEXT NOT NULL,
                content_type TEXT NOT NULL DEFAULT '',
                source_name TEXT NOT NULL DEFAULT '',
                quality_score INTEGER NOT NULL DEFAULT 0,
                topics TEXT NOT NULL DEFAULT '[]',
                url TEXT NOT NULL DEFAULT '',
                og_image TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                schema_version TEXT NOT NULL
            )
        SQL;

    /** Index name => definition. */
    private const array INDEX_DDLS = [
        'idx_search_meta_entity_type' => 'CREATE INDEX idx_search_meta_entity_type ON search_metadata(entity_type)',
        'idx_search_meta_content_type' => 'CREATE INDEX idx_search_meta_content_type ON search_metadata(content_type)',
        'idx_search_meta_source' => 'CREATE INDEX idx_search_meta_source ON search_metadata(source_name)',
    ];

    /** The tables FTS5 creates for `search_index`; they belong to it. */
    private const array SHADOW_TABLES = ['search_index_config', 'search_index_content', 'search_index_data', 'search_index_docsize', 'search_index_idx'];

    private const string LEGACY_RENAME = 'search_index_retired_porter';

    /**
     * @param bool $dedicatedFile true when provisioning a dedicated `search.database`
     *                            file from `search:reindex`; only the recovery text differs
     */
    public static function install(Connection $connection, bool $dedicatedFile = false): void
    {
        $definitions = self::liveDefinitions($connection);
        $differences = self::differences($definitions);
        if ($differences !== []) {
            throw new \RuntimeException(sprintf(
                '[SEARCH-DB001] The existing search projection does not have the schema waaseyaa/search owns: %s. Nothing was changed. '
                . 'Recovery (FW-SEARCH-PERSIST-01, docs/specs/search.md "Search projection schema"): %s',
                implode('; ', $differences),
                $dedicatedFile
                    ? 'the dedicated search.database file holds only the rebuildable projection; move that file aside and run `search:reindex` to recreate it.'
                    : 'back up the database, then move the listed objects aside yourself so the migration can create the expected projection. '
                        . 'The projection is rebuildable with `search:reindex`.',
            ));
        }

        $index = $definitions[self::INDEX_TABLE] ?? null;
        if ($index === null) {
            $connection->executeStatement(self::INDEX_DDL);
        } elseif (self::normalize($index) === self::normalize(self::LEGACY_PORTER_INDEX_DDL)) {
            // FTS5 tokenizers cannot be altered in place. Rebuild the table
            // and carry every row across; the new tokenizer re-tokenizes them.
            $connection->executeStatement('ALTER TABLE search_index RENAME TO ' . self::LEGACY_RENAME);
            $connection->executeStatement(self::INDEX_DDL);
            $connection->executeStatement('INSERT INTO search_index (document_id, title, body) SELECT document_id, title, body FROM ' . self::LEGACY_RENAME);
            $connection->executeStatement('DROP TABLE ' . self::LEGACY_RENAME);
        }

        if (!isset($definitions[self::METADATA_TABLE])) {
            $connection->executeStatement(self::METADATA_DDL);
        }

        foreach (self::INDEX_DDLS as $name => $ddl) {
            if (!isset($definitions[$name])) {
                $connection->executeStatement($ddl);
            }
        }
    }

    /** @return array<string, string> object name => stored definition, for every object this class owns or would collide with */
    private static function liveDefinitions(Connection $connection): array
    {
        $names = [self::INDEX_TABLE, self::METADATA_TABLE, self::LEGACY_RENAME, ...array_keys(self::INDEX_DDLS), ...self::SHADOW_TABLES];
        // SQLite object names are case-insensitive, so `Search_Metadata` would
        // collide with `search_metadata`; match and key them case-insensitively.
        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT name, type, tbl_name, sql FROM sqlite_master WHERE lower(name) IN (%s)', implode(', ', array_fill(0, count($names), '?'))),
            $names,
        );

        $definitions = [];
        foreach ($rows as $row) {
            $name = strtolower((string) $row['name']);
            $sql = (string) ($row['sql'] ?? '');
            // An index on another table that happens to share an owned name is
            // a different object; keep its table so it compares unequal.
            $definitions[$name] = (string) $row['type'] === 'index' && (string) $row['tbl_name'] !== self::METADATA_TABLE
                ? $sql . ' /* on ' . (string) $row['tbl_name'] . ' */'
                : $sql;
        }

        return $definitions;
    }

    /**
     * @param array<string, string> $definitions
     *
     * @return list<string>
     */
    private static function differences(array $definitions): array
    {
        $differences = [];

        if (isset($definitions[self::LEGACY_RENAME])) {
            $differences[] = sprintf('`%s` exists, left behind by an interrupted tokenizer upgrade', self::LEGACY_RENAME);
        }

        $index = $definitions[self::INDEX_TABLE] ?? null;
        if ($index === null) {
            foreach (self::SHADOW_TABLES as $shadow) {
                if (isset($definitions[$shadow])) {
                    $differences[] = sprintf('`%s` exists without the `search_index` table it belongs to', $shadow);
                }
            }
        } elseif (!in_array(self::normalize($index), [self::normalize(self::INDEX_DDL), self::normalize(self::LEGACY_PORTER_INDEX_DDL)], true)) {
            $differences[] = sprintf('`search_index` is defined as `%s`', self::normalize($index));
        }

        $metadata = $definitions[self::METADATA_TABLE] ?? null;
        if ($metadata !== null && self::normalize($metadata) !== self::normalize(self::METADATA_DDL)) {
            $differences[] = sprintf('`search_metadata` is defined as `%s`', self::normalize($metadata));
        }

        foreach (self::INDEX_DDLS as $name => $ddl) {
            $live = $definitions[$name] ?? null;
            if ($live !== null && self::normalize($live) !== self::normalize($ddl)) {
                $differences[] = sprintf('`%s` is defined as `%s`', $name, self::normalize($live));
            }
        }

        return $differences;
    }

    /** Whitespace-insensitive form of a stored definition; SQLite already drops `IF NOT EXISTS`. */
    private static function normalize(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/u', ' ', trim($sql));

        return (string) preg_replace('/\s*([(),])\s*/u', '$1', $sql);
    }
}
