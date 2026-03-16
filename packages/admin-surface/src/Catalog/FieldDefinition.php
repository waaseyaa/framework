<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Catalog;

/**
 * Value object for a field in the admin catalog.
 *
 * Maps to AdminSurfaceField in contract/types.ts.
 */
final class FieldDefinition
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
        private ?string $widget = null,
        private int $weight = 0,
        private bool $required = false,
        private bool $readOnly = false,
        private bool $accessRestricted = false,
    ) {}

    public function widget(string $widget): self
    {
        $this->widget = $widget;
        return $this;
    }

    public function weight(int $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;
        return $this;
    }

    public function readOnly(bool $readOnly = true): self
    {
        $this->readOnly = $readOnly;
        return $this;
    }

    public function accessRestricted(bool $restricted = true): self
    {
        $this->accessRestricted = $restricted;
        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function options(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'widget' => $this->widget,
            'weight' => $this->weight ?: null,
            'required' => $this->required ?: null,
            'readOnly' => $this->readOnly ?: null,
            'accessRestricted' => $this->accessRestricted ?: null,
            'options' => $this->options ?: null,
        ], fn($v) => $v !== null);
    }
}
