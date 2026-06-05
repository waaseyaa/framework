<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

/**
 * Value object returned by {@see LabelInheritanceResolver::resolve()}.
 *
 * Carries the resolved classification label together with its provenance:
 * whether it was inherited from a parent entity (and which one), or explicitly
 * set on the entity itself.
 *
 * @api
 */
final readonly class ClassificationDecision
{
    /**
     * @param string|null $label              Resolved classification label_id (null = unclassified).
     * @param string|null $inheritedFromUuid  Parent entity UUID when $inherited is true; null otherwise.
     * @param bool        $inherited          True when the label was resolved from a parent entity.
     * @param string|null $overriddenAt       ISO-8601 timestamp when an explicit label overrides an inherited one.
     */
    public function __construct(
        public readonly ?string $label,
        public readonly ?string $inheritedFromUuid,
        public readonly bool $inherited,
        public readonly ?string $overriddenAt = null,
    ) {}

    /**
     * Decision where the entity carries an explicit label (not inherited).
     */
    public static function explicit(?string $label): self
    {
        return new self(
            label: $label,
            inheritedFromUuid: null,
            inherited: false,
            overriddenAt: null,
        );
    }

    /**
     * Decision where the label is inherited from a parent entity.
     */
    public static function inherited(
        ?string $label,
        string $parentUuid,
        ?string $overriddenAt = null,
    ): self {
        return new self(
            label: $label,
            inheritedFromUuid: $parentUuid,
            inherited: true,
            overriddenAt: $overriddenAt,
        );
    }

    /**
     * Converts this decision to a storage-ready array suitable for writing
     * back to the entity's classification columns.
     *
     * @return array{classification_label: string|null, classification_inherited_from: string|null, classification_overridden_at: string|null}
     */
    public function toStorageArray(): array
    {
        return [
            'classification_label' => $this->label,
            'classification_inherited_from' => $this->inheritedFromUuid,
            'classification_overridden_at' => $this->overriddenAt,
        ];
    }
}
