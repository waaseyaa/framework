<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Form\Widget;

use Waaseyaa\Field\Entity\ClassificationLabelDefinition;

/**
 * Form widget for the classification_label field type.
 *
 * Renders a <select> element populated from ClassificationLabelDefinition
 * entities ordered by confidentiality_level ASC (least sensitive first).
 *
 * This widget is a lightweight DTO — it does not depend on Twig or any
 * rendering framework. Consumers (admin SPA, SSR) call toArray() to obtain
 * the option list and wire their own rendering logic.
 *
 * @api
 */
final class ClassificationLabelWidget
{
    /**
     * @param list<ClassificationLabelDefinition> $labelDefinitions
     *        All available label definitions, ordered by confidentiality_level ASC.
     */
    public function __construct(
        private readonly array $labelDefinitions,
    ) {}

    /**
     * Returns the widget descriptor as an array for JSON serialisation or
     * template rendering.
     *
     * @return array{
     *   type: 'select',
     *   options: list<array{value: string, label: string, confidentiality_level: int}>,
     *   nullable: true
     * }
     */
    public function toArray(): array
    {
        $options = [];
        foreach ($this->labelDefinitions as $def) {
            $options[] = [
                'value' => $def->getLabelId(),
                'label' => $def->getDisplayName(),
                'confidentiality_level' => $def->getConfidentialityLevel(),
            ];
        }

        return [
            'type' => 'select',
            'options' => $options,
            'nullable' => true,
        ];
    }
}
