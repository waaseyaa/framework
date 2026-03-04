<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\ViewModeConfigInterface;

final class EntityRenderer
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly FieldFormatterRegistry $formatterRegistry,
        private readonly ViewModeConfigInterface $viewModeConfig,
    ) {}

    /**
     * Build Twig variable bag for an entity + view mode.
     *
     * @return array{
     *   entity: EntityInterface,
     *   entity_type: string,
     *   bundle: string,
     *   view_mode: string,
     *   template_suggestions: list<string>,
     *   fields: array<string, array{raw: mixed, formatted: string, type: string}>
     * }
     */
    public function render(EntityInterface $entity, ViewMode|string $viewMode = 'full'): array
    {
        $mode = $viewMode instanceof ViewMode ? $viewMode->name : (string) $viewMode;
        if ($mode === '') {
            $mode = 'full';
        }

        $entityTypeId = $entity->getEntityTypeId();
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $fieldDefinitions = $definition->getFieldDefinitions();
        $display = $this->viewModeConfig->getDisplay($entityTypeId, $mode);
        $values = $entity->toArray();

        if ($display === []) {
            $display = $this->buildDefaultDisplay($fieldDefinitions, $values, $definition->getKeys());
        }

        uasort($display, static function (array $a, array $b): int {
            $wa = (int) ($a['weight'] ?? 0);
            $wb = (int) ($b['weight'] ?? 0);
            return $wa <=> $wb;
        });

        $fields = [];
        foreach ($display as $fieldName => $item) {
            $raw = $values[$fieldName] ?? null;
            $fieldType = (string) ($fieldDefinitions[$fieldName]['type'] ?? 'string');
            $formatterType = (string) ($item['formatter'] ?? $fieldType);
            $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];

            $fields[$fieldName] = [
                'raw' => $raw,
                'formatted' => $this->formatterRegistry->format($formatterType, $raw, $settings),
                'type' => $fieldType,
            ];
        }

        return [
            'entity' => $entity,
            'entity_type' => $entityTypeId,
            'bundle' => $entity->bundle(),
            'view_mode' => $mode,
            'template_suggestions' => $this->buildTemplateSuggestions($entityTypeId, (string) $entity->bundle(), $mode),
            'fields' => $fields,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildTemplateSuggestions(string $entityTypeId, string $bundle, string $mode): array
    {
        $modeSafe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($mode)) ?: 'full';
        $bundleSafe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($bundle)) ?: $entityTypeId;

        $suggestions = [
            sprintf('%s.%s.%s.html.twig', $entityTypeId, $bundleSafe, $modeSafe),
        ];

        if ($modeSafe !== 'full') {
            $suggestions[] = sprintf('%s.%s.full.html.twig', $entityTypeId, $bundleSafe);
        }

        $suggestions[] = sprintf('%s.%s.html.twig', $entityTypeId, $modeSafe);
        if ($modeSafe !== 'full') {
            $suggestions[] = sprintf('%s.full.html.twig', $entityTypeId);
        }
        $suggestions[] = 'entity.html.twig';

        return array_values(array_unique($suggestions));
    }

    /**
     * @param array<string, array<string, mixed>> $fieldDefinitions
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @return array<string, array{formatter: string, settings: array<string, mixed>, weight: int}>
     */
    private function buildDefaultDisplay(array $fieldDefinitions, array $values, array $entityKeys): array
    {
        $hidden = array_values($entityKeys);
        $display = [];
        $weight = 0;

        foreach ($values as $name => $value) {
            if (in_array($name, $hidden, true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $display[$name] = [
                'formatter' => (string) ($fieldDefinitions[$name]['type'] ?? 'string'),
                'settings' => [],
                'weight' => $weight++,
            ];
        }

        return $display;
    }
}
