<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Schema\Diff\CanonicalJson;

/** Canonical fingerprint of the logical SQLite schema, never of file bytes. */
final readonly class LogicalSchemaFingerprint
{
    public static function capture(Connection $connection): string
    {
        $rows = $connection->executeQuery(
            "SELECT type, name, tbl_name, sql
             FROM sqlite_schema
             WHERE name NOT LIKE 'sqlite_%'
             ORDER BY type, name, tbl_name",
        )->fetchAllAssociative();

        $schema = [];
        foreach ($rows as $row) {
            $schema[] = [
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'table' => (string) $row['tbl_name'],
                'sql' => self::normalizeSql($row['sql'] ?? null),
            ];
        }

        return hash('sha256', CanonicalJson::encode([
            'domain' => 'waaseyaa.logical-sqlite-schema.v1',
            'objects' => $schema,
        ]));
    }

    private static function normalizeSql(mixed $sql): ?string
    {
        if (!is_string($sql)) {
            return null;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $sql));
    }
}
