<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

/**
 * Appends and reads entity-write audit entries from a JSONL file.
 *
 * Entries are stored at storage/framework/entity-audit.jsonl.
 * Atomic append via FILE_APPEND | LOCK_EX prevents interleaving.
 */
final class EntityAuditLogger
{
    private const AUDIT_FILE = '/storage/framework/entity-audit.jsonl';

    public function __construct(private readonly string $projectRoot) {}

    public function append(EntityAuditEntry $entry): void
    {
        $file = $this->auditFile();
        $this->ensureDirectory(dirname($file));

        file_put_contents(
            $file,
            json_encode($entry->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Read audit entries, optionally filtered by entity type.
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $entityTypeFilter = ''): array
    {
        $file = $this->auditFile();

        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            try {
                /** @var array<string, mixed> $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if ($entityTypeFilter === '' || $entry[EntityAuditKey::EntityType->value] === $entityTypeFilter) {
                    $entries[] = $entry;
                }
            } catch (\JsonException) {
                // Skip malformed lines without crashing.
            }
        }

        return $entries;
    }

    private function auditFile(): string
    {
        return $this->projectRoot . self::AUDIT_FILE;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }
}
