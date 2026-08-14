<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Wire;

use Waaseyaa\PageBuilder\Command\AddBlock;
use Waaseyaa\PageBuilder\Command\AddSection;
use Waaseyaa\PageBuilder\Command\ChangeSectionLayout;
use Waaseyaa\PageBuilder\Command\ConfigureBlock;
use Waaseyaa\PageBuilder\Command\DuplicateBlock;
use Waaseyaa\PageBuilder\Command\DuplicateSection;
use Waaseyaa\PageBuilder\Command\EditCommand;
use Waaseyaa\PageBuilder\Command\MoveBlock;
use Waaseyaa\PageBuilder\Command\MoveSection;
use Waaseyaa\PageBuilder\Command\RemoveBlock;
use Waaseyaa\PageBuilder\Command\RemoveSection;
use Waaseyaa\PageBuilder\Wire\Exception\InvalidWireCommandException;

/** @api */
final readonly class EditCommandDecoder
{
    /** @param array<string, mixed> $payload */
    public function decode(array $payload): EditCommand
    {
        $type = $this->string($payload, 'type');

        return match ($type) {
            'add_block' => $this->addBlock($payload),
            'duplicate_block' => $this->duplicateBlock($payload),
            'configure_block' => $this->configureBlock($payload),
            'move_block' => $this->moveBlock($payload),
            'remove_block' => $this->removeBlock($payload),
            'add_section' => $this->addSection($payload),
            'duplicate_section' => $this->duplicateSection($payload),
            'move_section' => $this->moveSection($payload),
            'remove_section' => $this->removeSection($payload),
            'change_section_layout' => $this->changeSectionLayout($payload),
            default => throw new InvalidWireCommandException('Unknown page-builder command type.'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function addBlock(array $payload): AddBlock
    {
        $this->exactKeys($payload, ['type', 'section_id', 'region_id', 'position', 'block']);

        return new AddBlock(
            $this->string($payload, 'section_id'),
            $this->string($payload, 'region_id'),
            $this->position($payload, 'position'),
            $this->map($payload, 'block'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function duplicateBlock(array $payload): DuplicateBlock
    {
        $this->exactKeys($payload, ['type', 'source_block_id', 'duplicate_block_id']);

        return new DuplicateBlock($this->string($payload, 'source_block_id'), $this->string($payload, 'duplicate_block_id'));
    }

    /** @param array<string, mixed> $payload */
    private function configureBlock(array $payload): ConfigureBlock
    {
        $this->exactKeys($payload, ['type', 'block_id', 'config']);

        return new ConfigureBlock($this->string($payload, 'block_id'), $this->map($payload, 'config'));
    }

    /** @param array<string, mixed> $payload */
    private function moveBlock(array $payload): MoveBlock
    {
        $this->exactKeys($payload, ['type', 'block_id', 'destination_section_id', 'destination_region_id', 'position']);

        return new MoveBlock(
            $this->string($payload, 'block_id'),
            $this->string($payload, 'destination_section_id'),
            $this->string($payload, 'destination_region_id'),
            $this->position($payload, 'position'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function removeBlock(array $payload): RemoveBlock
    {
        $this->exactKeys($payload, ['type', 'block_id']);

        return new RemoveBlock($this->string($payload, 'block_id'));
    }

    /** @param array<string, mixed> $payload */
    private function addSection(array $payload): AddSection
    {
        $this->exactKeys($payload, ['type', 'position', 'section']);

        return new AddSection($this->position($payload, 'position'), $this->map($payload, 'section'));
    }

    /** @param array<string, mixed> $payload */
    private function duplicateSection(array $payload): DuplicateSection
    {
        $this->exactKeys($payload, ['type', 'source_section_id', 'duplicate_section_id', 'duplicate_block_ids']);
        $ids = $this->map($payload, 'duplicate_block_ids');
        foreach ($ids as $sourceId => $duplicateId) {
            if (!is_string($sourceId) || '' === $sourceId || !is_string($duplicateId) || '' === $duplicateId) {
                throw new InvalidWireCommandException('Section duplication block IDs must be non-empty strings.');
            }
        }

        /** @var array<string, string> $ids */
        return new DuplicateSection(
            $this->string($payload, 'source_section_id'),
            $this->string($payload, 'duplicate_section_id'),
            $ids,
        );
    }

    /** @param array<string, mixed> $payload */
    private function moveSection(array $payload): MoveSection
    {
        $this->exactKeys($payload, ['type', 'section_id', 'position']);

        return new MoveSection($this->string($payload, 'section_id'), $this->position($payload, 'position'));
    }

    /** @param array<string, mixed> $payload */
    private function removeSection(array $payload): RemoveSection
    {
        $this->exactKeys($payload, ['type', 'section_id']);

        return new RemoveSection($this->string($payload, 'section_id'));
    }

    /** @param array<string, mixed> $payload */
    private function changeSectionLayout(array $payload): ChangeSectionLayout
    {
        $this->exactKeys($payload, ['type', 'section_id', 'layout_id', 'layout_version']);

        return new ChangeSectionLayout(
            $this->string($payload, 'section_id'),
            $this->string($payload, 'layout_id'),
            $this->positiveInt($payload, 'layout_version'),
        );
    }

    /** @param array<string, mixed> $payload @param list<string> $expected */
    private function exactKeys(array $payload, array $expected): void
    {
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidWireCommandException('Page-builder command fields do not match the command contract.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new InvalidWireCommandException("Page-builder command field {$key} must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function position(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidWireCommandException("Page-builder command field {$key} must be a non-negative integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidWireCommandException("Page-builder command field {$key} must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function map(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidWireCommandException("Page-builder command field {$key} must be an object.");
        }

        return $value;
    }
}
