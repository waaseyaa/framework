<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Schema;

use Waaseyaa\Database\DatabaseInterface;

/** Read-only fail-closed guard for runtime schema dependencies. */
final class SchemaRequirement
{
    /**
     * @param list<string> $requiredFields
     */
    public static function assertAvailable(
        DatabaseInterface $database,
        string $table,
        array $requiredFields,
        string $migrationId,
    ): void {
        $schema = $database->schema();

        try {
            if (!$schema->tableExists($table)) {
                self::refuse($table, ['table'], $migrationId);
            }

            $missing = [];
            // One live column inspection for the complete requirement. Keep
            // the existing interface path for non-DBAL schema adapters.
            $fields = $schema instanceof DBALSchema && $requiredFields !== []
                ? $schema->fieldNames($table)
                : null;
            foreach ($requiredFields as $field) {
                $exists = $fields !== null
                    ? in_array($field, $fields, true)
                    : $schema->fieldExists($table, $field);
                if (!$exists) {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                self::refuse($table, $missing, $migrationId);
            }
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '[S1-DB106]')) {
                throw $exception;
            }

            throw new \RuntimeException(sprintf(
                '[S1-DB106] Required runtime schema inspection failed for table "%s". Apply migration "%s" through the schema coordinator.',
                $table,
                $migrationId,
            ), previous: $exception);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                '[S1-DB106] Required runtime schema inspection failed for table "%s". Apply migration "%s" through the schema coordinator.',
                $table,
                $migrationId,
            ), previous: $exception);
        }
    }

    /**
     * @param non-empty-list<string> $missing
     */
    private static function refuse(string $table, array $missing, string $migrationId): never
    {
        throw new \RuntimeException(sprintf(
            '[S1-DB106] Required runtime schema is unavailable for table "%s"; missing: %s. Apply migration "%s" through the schema coordinator.',
            $table,
            implode(', ', $missing),
            $migrationId,
        ));
    }
}
