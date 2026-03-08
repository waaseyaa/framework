<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

use Waaseyaa\Entity\EntityTypeInterface;

/**
 * A snapshot of the entity type registry status produced at boot time.
 *
 * Contains registered types, disabled type IDs, and per-type compatibility
 * information derived from the type's manifest (when available).
 */
final class BootDiagnosticReport
{
    /**
     * @param array<string, EntityTypeInterface> $registeredTypes
     * @param string[]                           $disabledTypeIds
     * @param array<string, mixed>               $schemaCompatibility  type_id → compatibility mode string
     */
    public function __construct(
        public readonly array $registeredTypes,
        public readonly array $disabledTypeIds,
        public readonly array $schemaCompatibility,
    ) {}

    /** @return string[] */
    public function enabledTypeIds(): array
    {
        return array_values(array_filter(
            array_keys($this->registeredTypes),
            fn(string $id): bool => !in_array($id, $this->disabledTypeIds, true),
        ));
    }

    public function hasEnabledTypes(): bool
    {
        return $this->enabledTypeIds() !== [];
    }

    /**
     * @return array{
     *   registered: string[],
     *   disabled: string[],
     *   enabled: string[],
     *   schema_compatibility: array<string, mixed>,
     *   healthy: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'registered'           => array_keys($this->registeredTypes),
            'disabled'             => $this->disabledTypeIds,
            'enabled'              => $this->enabledTypeIds(),
            'schema_compatibility' => $this->schemaCompatibility,
            'healthy'              => $this->hasEnabledTypes(),
        ];
    }
}
