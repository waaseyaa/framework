<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Catalog;

/**
 * Value object for an action in the admin catalog.
 *
 * Maps to AdminSurfaceAction in contract/types.ts.
 */
final class ActionDefinition
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private string $scope = 'entity',
        private ?string $confirmation = null,
        private bool $dangerous = false,
    ) {}

    public function collection(): self
    {
        $this->scope = 'collection';
        return $this;
    }

    public function confirm(string $message): self
    {
        $this->confirmation = $message;
        return $this;
    }

    public function dangerous(bool $dangerous = true): self
    {
        $this->dangerous = $dangerous;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'scope' => $this->scope,
            'confirmation' => $this->confirmation,
            'dangerous' => $this->dangerous ?: null,
        ], fn($v) => $v !== null);
    }
}
