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
    public function getDisabledTypeIds(?string $tenantId = null): array
    {
        $status = $this->readStatus();
        $tenantId = $this->normalizeTenantId($tenantId);

        if ($tenantId !== null) {
            $tenantDisabled = $status['tenants'][$tenantId] ?? [];
            if ($status['disabled'] === []) {
                return $tenantDisabled;
            }

            return array_values(array_unique(array_merge($status['disabled'], $tenantDisabled)));
        }

        return $status['disabled'];
    }

    /**
     * @return string[]
     */
    public function getTenantIds(): array
    {
        $status = $this->readStatus();

        return array_keys($status['tenants']);
    }

    public function isDisabled(string $entityTypeId, ?string $tenantId = null): bool
    {
        return in_array($entityTypeId, $this->getDisabledTypeIds($tenantId), true);
    }

    /**
     * Disable an entity type, recording the actor in the audit log.
     */
    public function disable(string $entityTypeId, int|string $actorId, ?string $tenantId = null): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $disabled = $this->getDisabledTypeIds($tenantId);

        if (!in_array($entityTypeId, $disabled, true)) {
            $disabled[] = $entityTypeId;
        }

        $this->writeStatus($disabled, $tenantId);
        $this->appendAudit($entityTypeId, 'disabled', $actorId, $tenantId);
    }

    /**
     * Re-enable a disabled entity type, recording the actor in the audit log.
     */
    public function enable(string $entityTypeId, int|string $actorId, ?string $tenantId = null): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $disabled = array_values(array_filter(
            $this->getDisabledTypeIds($tenantId),
            static fn(string $id): bool => $id !== $entityTypeId,
        ));

        $this->writeStatus($disabled, $tenantId);
        $this->appendAudit($entityTypeId, 'enabled', $actorId, $tenantId);
    }

    /**
     * Read audit log entries, optionally filtered by entity type ID.
     *
     * @return list<array{entity_type_id: string, action: string, actor_id: string, timestamp: string}>
     */
    public function readAuditLog(string $entityTypeFilter = '', ?string $tenantId = null): array
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

        $tenantId = $this->normalizeTenantId($tenantId);

        foreach ($lines as $line) {
            try {
                /** @var array{entity_type_id: string, action: string, actor_id: string, timestamp: string, tenant_id?: string} $entry */
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $entryTenant = isset($entry[Audit\LifecycleAuditKey::TenantId->value]) ? (string) $entry[Audit\LifecycleAuditKey::TenantId->value] : null;

                $typeMatch = $entityTypeFilter === '' || $entry[Audit\LifecycleAuditKey::EntityTypeId->value] === $entityTypeFilter;
                $tenantMatch = $tenantId === null || $entryTenant === $tenantId;

                if ($typeMatch && $tenantMatch) {
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

    /**
     * @param string[] $disabled
     */
    private function writeStatus(array $disabled, ?string $tenantId = null): void
    {
        $tenantId = $this->normalizeTenantId($tenantId);
        $status = $this->readStatus();

        if ($tenantId === null) {
            $status['disabled'] = array_values($disabled);
        } else {
            $status['tenants'][$tenantId] = array_values($disabled);
        }

        $file = $this->statusFile();
        $this->ensureDirectory(dirname($file));

        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents(
            $tmp,
            json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        rename($tmp, $file);
    }

    private function appendAudit(string $entityTypeId, string $action, int|string $actorId, ?string $tenantId = null): void
    {
        $file = $this->auditFile();
        $this->ensureDirectory(dirname($file));

        $payload = [
            Audit\LifecycleAuditKey::EntityTypeId->value => $entityTypeId,
            Audit\LifecycleAuditKey::Action->value       => $action,
            Audit\LifecycleAuditKey::ActorId->value      => (string) $actorId,
            Audit\LifecycleAuditKey::Timestamp->value    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $tenantId = $this->normalizeTenantId($tenantId);
        if ($tenantId !== null) {
            $payload[Audit\LifecycleAuditKey::TenantId->value] = $tenantId;
        }

        $entry = json_encode($payload, JSON_THROW_ON_ERROR);

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

    /**
     * @return array{disabled: string[], tenants: array<string, string[]>}
     */
    private function readStatus(): array
    {
        $file = $this->statusFile();

        if (!file_exists($file)) {
            return ['disabled' => [], 'tenants' => []];
        }

        try {
            /** @var array{disabled?: string[], tenants?: array<string, string[]>} $data */
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['disabled' => [], 'tenants' => []];
        }

        return [
            'disabled' => array_values($data['disabled'] ?? []),
            'tenants' => $data['tenants'] ?? [],
        ];
    }

    private function normalizeTenantId(?string $tenantId): ?string
    {
        $tenantId = $tenantId !== null ? trim($tenantId) : null;

        return $tenantId !== '' ? $tenantId : null;
    }
}
