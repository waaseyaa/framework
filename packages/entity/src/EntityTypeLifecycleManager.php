<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Manages the enabled/disabled lifecycle state for registered entity types.
 *
 * State is persisted as a JSON file at storage/framework/entity-type-status.json.
 * Each disable/enable action appends an audit entry to
 * storage/framework/entity-type-audit.jsonl (one JSON object per line).
 *
 * Atomic writes (write-to-temp + rename) prevent serving partial files.
 */
final class EntityTypeLifecycleManager
{
    private const STATUS_FILE = '/storage/framework/entity-type-status.json';
    private const AUDIT_FILE  = '/storage/framework/entity-type-audit.jsonl';

    public function __construct(private readonly string $projectRoot) {}

    /**
     * Return the IDs of all currently disabled entity types.
     *
     * @return string[]
     */
    public function getDisabledTypeIds(): array
    {
        $file = $this->statusFile();

        if (!file_exists($file)) {
            return [];
        }

        try {
            /** @var array{disabled?: string[]} $data */
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            return $data['disabled'] ?? [];
        } catch (\JsonException) {
            return [];
        }
    }

    public function isDisabled(string $entityTypeId): bool
    {
        return in_array($entityTypeId, $this->getDisabledTypeIds(), true);
    }

    /**
     * Disable an entity type, recording the actor in the audit log.
     */
    public function disable(string $entityTypeId, int|string $actorId): void
    {
        $disabled = $this->getDisabledTypeIds();

        if (!in_array($entityTypeId, $disabled, true)) {
            $disabled[] = $entityTypeId;
        }

        $this->writeStatus($disabled);
        $this->appendAudit($entityTypeId, 'disabled', $actorId);
    }

    /**
     * Re-enable a disabled entity type, recording the actor in the audit log.
     */
    public function enable(string $entityTypeId, int|string $actorId): void
    {
        $disabled = array_values(array_filter(
            $this->getDisabledTypeIds(),
            static fn(string $id): bool => $id !== $entityTypeId,
        ));

        $this->writeStatus($disabled);
        $this->appendAudit($entityTypeId, 'enabled', $actorId);
    }

    /**
     * Read audit log entries, optionally filtered by entity type ID.
     *
     * @return list<array{entity_type_id: string, action: string, actor_id: string, timestamp: string}>
     */
    public function readAuditLog(string $entityTypeFilter = ''): array
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
                /** @var array{entity_type_id: string, action: string, actor_id: string, timestamp: string} $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if ($entityTypeFilter === '' || $entry['entity_type_id'] === $entityTypeFilter) {
                    $entries[] = $entry;
                }
            } catch (\JsonException) {
                // Skip malformed lines without crashing.
            }
        }

        return $entries;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** @param string[] $disabled */
    private function writeStatus(array $disabled): void
    {
        $file = $this->statusFile();
        $this->ensureDirectory(dirname($file));

        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents(
            $tmp,
            json_encode(['disabled' => array_values($disabled)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        rename($tmp, $file);
    }

    private function appendAudit(string $entityTypeId, string $action, int|string $actorId): void
    {
        $file = $this->auditFile();
        $this->ensureDirectory(dirname($file));

        $entry = json_encode([
            'entity_type_id' => $entityTypeId,
            'action'         => $action,
            'actor_id'       => (string) $actorId,
            'timestamp'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    private function statusFile(): string
    {
        return $this->projectRoot . self::STATUS_FILE;
    }

    private function auditFile(): string
    {
        return $this->projectRoot . self::AUDIT_FILE;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
