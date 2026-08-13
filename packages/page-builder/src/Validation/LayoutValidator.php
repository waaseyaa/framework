<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Validation;

use Opis\JsonSchema\Validator;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\Exception\UnknownDefinitionException;
use Waaseyaa\PageBuilder\Document\LayoutDocument;

/** @api */
final readonly class LayoutValidator
{
    public function __construct(
        private DefinitionRegistry $registry,
        private Validator $schemaValidator = new Validator(),
    ) {}

    public function validate(LayoutDocument $document): ValidationResult
    {
        $violations = [];
        $templateRef = $document->template();

        try {
            $template = $this->registry->template($templateRef['id'], $templateRef['version']);
        } catch (UnknownDefinitionException) {
            return new ValidationResult([[
                'code' => 'template.definition.unknown',
                'pointer' => '/template',
                'detail' => "Unknown template definition: {$templateRef['id']}@{$templateRef['version']}",
            ]]);
        }

        $instanceIds = [];
        foreach ($document->sections() as $sectionIndex => $section) {
            $sectionPointer = "/sections/{$sectionIndex}";
            $this->recordUnknownFields($section, ['id', 'layout', 'regions'], $sectionPointer, 'section', $violations);
            $this->recordInstanceId($section['id'] ?? null, $sectionPointer . '/id', $instanceIds, $violations);

            $layoutRef = $section['layout'] ?? null;
            if (!is_array($layoutRef) || !is_string($layoutRef['id'] ?? null) || !is_int($layoutRef['version'] ?? null)) {
                $violations[] = $this->violation('layout.reference.invalid', $sectionPointer . '/layout', 'Layout reference must contain id and version');
                continue;
            }
            $this->recordUnknownFields($layoutRef, ['id', 'version'], $sectionPointer . '/layout', 'layout', $violations);

            try {
                $layout = $this->registry->layout($layoutRef['id'], $layoutRef['version']);
            } catch (UnknownDefinitionException) {
                $violations[] = $this->violation('layout.definition.unknown', $sectionPointer . '/layout', 'Unknown layout definition');
                continue;
            }

            if (!in_array($layout->id, $template->allowedLayouts, true)) {
                $violations[] = $this->violation('template.layout.forbidden', $sectionPointer . '/layout', 'Layout is not allowed by the template');
            }

            $regions = $section['regions'] ?? null;
            if (!is_array($regions) || array_is_list($regions)) {
                $violations[] = $this->violation('layout.regions.invalid', $sectionPointer . '/regions', 'Regions must be an object');
                continue;
            }

            foreach ($layout->requiredRegions as $requiredRegion) {
                if (!array_key_exists($requiredRegion, $regions)) {
                    $violations[] = $this->violation('layout.region.required', $sectionPointer . '/regions', "Required region is missing: {$requiredRegion}");
                }
            }

            foreach ($regions as $regionId => $blocks) {
                $regionPointer = $sectionPointer . '/regions/' . $this->escapePointer((string) $regionId);
                if (!in_array($regionId, $layout->regions, true)) {
                    $violations[] = $this->violation('layout.region.unknown', $regionPointer, "Unknown layout region: {$regionId}");
                }
                if (!is_array($blocks) || !array_is_list($blocks)) {
                    $violations[] = $this->violation('layout.region.blocks.invalid', $regionPointer, 'Region blocks must be a list');
                    continue;
                }

                foreach ($blocks as $blockIndex => $block) {
                    $blockPointer = $regionPointer . '/' . $blockIndex;
                    if (!is_array($block)) {
                        $violations[] = $this->violation('block.invalid', $blockPointer, 'Block must be an object');
                        continue;
                    }
                    $this->recordUnknownFields($block, ['id', 'type', 'version', 'config'], $blockPointer, 'block', $violations);
                    $this->recordInstanceId($block['id'] ?? null, $blockPointer . '/id', $instanceIds, $violations);
                    $type = $block['type'] ?? null;
                    $version = $block['version'] ?? null;
                    if (!is_string($type) || !is_int($version)) {
                        $violations[] = $this->violation('block.reference.invalid', $blockPointer, 'Block reference must contain type and version');
                        continue;
                    }

                    try {
                        $definition = $this->registry->block($type, $version);
                    } catch (UnknownDefinitionException) {
                        $violations[] = $this->violation('block.definition.unknown', $blockPointer, 'Unknown block definition');
                        continue;
                    }

                    if (!in_array($type, $layout->allowedBlocks, true) || !in_array($type, $template->allowedBlocks, true)) {
                        $violations[] = $this->violation('block.placement.forbidden', $blockPointer, 'Block is not allowed in this layout and template');
                    }

                    $config = $block['config'] ?? null;
                    $data = json_decode(json_encode($config, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
                    $schema = json_decode(json_encode($definition->configSchema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
                    if (!$this->schemaValidator->validate($data, $schema)->isValid()) {
                        $violations[] = $this->violation('block.config.invalid', $blockPointer . '/config', 'Block configuration does not match its schema');
                    }
                }
            }
        }

        usort($violations, static fn(array $left, array $right): int => [$left['code'], $left['pointer']] <=> [$right['code'], $right['pointer']]);

        return new ValidationResult($violations);
    }

    /**
     * @param array<string, string>                                      $instanceIds
     * @param list<array{code: string, pointer: string, detail: string}> $violations
     */
    private function recordInstanceId(mixed $id, string $pointer, array &$instanceIds, array &$violations): void
    {
        if (!is_string($id) || 1 !== preg_match('/^(?:sec|blk)_[A-Za-z0-9_-]+$/D', $id)) {
            $violations[] = $this->violation('document.instance_id.invalid', $pointer, 'Instance ID is invalid');

            return;
        }
        if (isset($instanceIds[$id])) {
            $violations[] = $this->violation('document.instance_id.duplicate', $pointer, "Duplicate instance ID: {$id}");

            return;
        }
        $instanceIds[$id] = $pointer;
    }

    /**
     * @param array<string, mixed>                                      $value
     * @param list<string>                                              $allowed
     * @param list<array{code: string, pointer: string, detail: string}> $violations
     */
    private function recordUnknownFields(array $value, array $allowed, string $pointer, string $kind, array &$violations): void
    {
        foreach (array_keys($value) as $field) {
            if (!in_array($field, $allowed, true)) {
                $violations[] = $this->violation(
                    $kind . '.field.unknown',
                    $pointer . '/' . $this->escapePointer($field),
                    "Unknown {$kind} field: {$field}",
                );
            }
        }
    }

    /** @return array{code: string, pointer: string, detail: string} */
    private function violation(string $code, string $pointer, string $detail): array
    {
        return compact('code', 'pointer', 'detail');
    }

    private function escapePointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
