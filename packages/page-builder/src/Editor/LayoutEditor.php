<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Editor;

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
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\Exception\UnknownDefinitionException;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Editor\Exception\InvalidEditCommandException;
use Waaseyaa\PageBuilder\Editor\Exception\StaleDocumentFingerprintException;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

/** @api */
final readonly class LayoutEditor
{
    public function __construct(
        private CanonicalLayoutCodec $codec,
        private LayoutValidator $validator,
        private DefinitionRegistry $registry,
    ) {}

    public function fingerprint(LayoutDocument $document): string
    {
        return hash('sha256', $this->codec->encode($document));
    }

    public function apply(LayoutDocument $document, string $expectedFingerprint, EditCommand $command): EditResult
    {
        $actualFingerprint = $this->fingerprint($document);
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)
            || !hash_equals($actualFingerprint, $expectedFingerprint)) {
            throw new StaleDocumentFingerprintException('Layout document fingerprint is stale or invalid');
        }

        $payload = $document->toArray();
        $summary = match (true) {
            $command instanceof AddBlock => $this->addBlock($payload, $command),
            $command instanceof DuplicateBlock => $this->duplicateBlock($payload, $command),
            $command instanceof ConfigureBlock => $this->configureBlock($payload, $command),
            $command instanceof MoveBlock => $this->moveBlock($payload, $command),
            $command instanceof RemoveBlock => $this->removeBlock($payload, $command),
            $command instanceof AddSection => $this->addSection($payload, $command),
            $command instanceof DuplicateSection => $this->duplicateSection($payload, $command),
            $command instanceof MoveSection => $this->moveSection($payload, $command),
            $command instanceof RemoveSection => $this->removeSection($payload, $command),
            $command instanceof ChangeSectionLayout => $this->changeSectionLayout($payload, $command),
            default => throw new InvalidEditCommandException('command.unsupported', 'Unsupported layout edit command'),
        };

        $edited = LayoutDocument::fromArray($payload);
        $validation = $this->validator->validate($edited);
        if (!$validation->isValid()) {
            throw new InvalidEditCommandException(
                'command.result.invalid',
                'Layout edit would produce an invalid document',
                $validation->violations(),
            );
        }

        return new EditResult($edited, $this->fingerprint($edited), $summary);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function addBlock(array &$payload, AddBlock $command): array
    {
        $sectionIndex = $this->sectionIndex($payload, $command->sectionId);
        $blocks = & $this->region($payload, $sectionIndex, $command->regionId);
        $this->assertPosition($command->position, count($blocks), allowEnd: true);
        array_splice($blocks, $command->position, 0, [$command->block]);

        return ['code' => 'block.added', 'target' => (string) ($command->block['id'] ?? '')];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function configureBlock(array &$payload, ConfigureBlock $command): array
    {
        [$sectionIndex, $regionId, $blockIndex] = $this->blockLocation($payload, $command->blockId);
        $payload['sections'][$sectionIndex]['regions'][$regionId][$blockIndex]['config'] = $command->config;

        return ['code' => 'block.configured', 'target' => $command->blockId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function duplicateBlock(array &$payload, DuplicateBlock $command): array
    {
        [$sectionIndex, $regionId, $blockIndex] = $this->blockLocation($payload, $command->sourceBlockId);
        $blocks = & $this->region($payload, $sectionIndex, $regionId);
        $duplicate = $blocks[$blockIndex];
        $duplicate['id'] = $command->duplicateBlockId;
        array_splice($blocks, $blockIndex + 1, 0, [$duplicate]);

        return ['code' => 'block.duplicated', 'target' => $command->duplicateBlockId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function moveBlock(array &$payload, MoveBlock $command): array
    {
        [$sourceSectionIndex, $sourceRegionId, $sourceBlockIndex] = $this->blockLocation($payload, $command->blockId);
        $sourceBlocks = & $this->region($payload, $sourceSectionIndex, $sourceRegionId);
        $removed = array_splice($sourceBlocks, $sourceBlockIndex, 1);
        $destinationSectionIndex = $this->sectionIndex($payload, $command->destinationSectionId);
        $destinationBlocks = & $this->region($payload, $destinationSectionIndex, $command->destinationRegionId);
        $this->assertPosition($command->position, count($destinationBlocks), allowEnd: true);
        array_splice($destinationBlocks, $command->position, 0, $removed);

        return ['code' => 'block.moved', 'target' => $command->blockId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function removeBlock(array &$payload, RemoveBlock $command): array
    {
        [$sectionIndex, $regionId, $blockIndex] = $this->blockLocation($payload, $command->blockId);
        $blocks = & $this->region($payload, $sectionIndex, $regionId);
        array_splice($blocks, $blockIndex, 1);

        return ['code' => 'block.removed', 'target' => $command->blockId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function addSection(array &$payload, AddSection $command): array
    {
        $this->assertPosition($command->position, count($payload['sections']), allowEnd: true);
        array_splice($payload['sections'], $command->position, 0, [$command->section]);

        return ['code' => 'section.added', 'target' => (string) ($command->section['id'] ?? '')];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function duplicateSection(array &$payload, DuplicateSection $command): array
    {
        $sourceIndex = $this->sectionIndex($payload, $command->sourceSectionId);
        $duplicate = $payload['sections'][$sourceIndex];
        $sourceBlockIds = [];
        foreach ($duplicate['regions'] as $blocks) {
            foreach ($blocks as $block) {
                $sourceBlockIds[] = (string) ($block['id'] ?? '');
            }
        }
        $mappedSourceIds = array_keys($command->duplicateBlockIds);
        sort($sourceBlockIds, SORT_STRING);
        sort($mappedSourceIds, SORT_STRING);
        if ($sourceBlockIds !== $mappedSourceIds) {
            throw new InvalidEditCommandException(
                'command.duplicate_section.block_ids_mismatch',
                'Section duplication requires one fresh ID for every source block.',
            );
        }

        $duplicate['id'] = $command->duplicateSectionId;
        foreach ($duplicate['regions'] as &$blocks) {
            foreach ($blocks as &$block) {
                $block['id'] = $command->duplicateBlockIds[(string) $block['id']];
            }
            unset($block);
        }
        unset($blocks);
        array_splice($payload['sections'], $sourceIndex + 1, 0, [$duplicate]);

        return ['code' => 'section.duplicated', 'target' => $command->duplicateSectionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function moveSection(array &$payload, MoveSection $command): array
    {
        $sourceIndex = $this->sectionIndex($payload, $command->sectionId);
        $section = array_splice($payload['sections'], $sourceIndex, 1);
        $this->assertPosition($command->position, count($payload['sections']), allowEnd: true);
        array_splice($payload['sections'], $command->position, 0, $section);

        return ['code' => 'section.moved', 'target' => $command->sectionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function removeSection(array &$payload, RemoveSection $command): array
    {
        $sectionIndex = $this->sectionIndex($payload, $command->sectionId);
        array_splice($payload['sections'], $sectionIndex, 1);

        return ['code' => 'section.removed', 'target' => $command->sectionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: string, target: string}
     */
    private function changeSectionLayout(array &$payload, ChangeSectionLayout $command): array
    {
        try {
            $layout = $this->registry->layout($command->layoutId, $command->layoutVersion);
        } catch (UnknownDefinitionException $exception) {
            throw new InvalidEditCommandException('command.layout.unknown', $exception->getMessage(), previous: $exception);
        }

        $sectionIndex = $this->sectionIndex($payload, $command->sectionId);
        $existingRegions = $payload['sections'][$sectionIndex]['regions'] ?? [];
        foreach ($existingRegions as $regionId => $blocks) {
            if (!in_array($regionId, $layout->regions, true) && [] !== $blocks) {
                throw new InvalidEditCommandException(
                    'command.layout.content_would_be_removed',
                    "Layout change would remove populated region: {$regionId}",
                );
            }
        }

        $regions = [];
        foreach ($layout->regions as $regionId) {
            $regions[$regionId] = $existingRegions[$regionId] ?? [];
        }
        $payload['sections'][$sectionIndex]['layout'] = ['id' => $layout->id, 'version' => $layout->version];
        $payload['sections'][$sectionIndex]['regions'] = $regions;

        return ['code' => 'section.layout_changed', 'target' => $command->sectionId];
    }

    /** @param array<string, mixed> $payload */
    private function sectionIndex(array $payload, string $sectionId): int
    {
        foreach ($payload['sections'] as $index => $section) {
            if (($section['id'] ?? null) === $sectionId) {
                return $index;
            }
        }

        throw new InvalidEditCommandException('command.target.unknown', "Unknown section target: {$sectionId}");
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{int, string, int}
     */
    private function blockLocation(array $payload, string $blockId): array
    {
        foreach ($payload['sections'] as $sectionIndex => $section) {
            foreach (($section['regions'] ?? []) as $regionId => $blocks) {
                foreach ($blocks as $blockIndex => $block) {
                    if (($block['id'] ?? null) === $blockId) {
                        return [$sectionIndex, (string) $regionId, $blockIndex];
                    }
                }
            }
        }

        throw new InvalidEditCommandException('command.target.unknown', "Unknown block target: {$blockId}");
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function &region(array &$payload, int $sectionIndex, string $regionId): array
    {
        if (!isset($payload['sections'][$sectionIndex]['regions'])
            || !array_key_exists($regionId, $payload['sections'][$sectionIndex]['regions'])
            || !is_array($payload['sections'][$sectionIndex]['regions'][$regionId])) {
            throw new InvalidEditCommandException('command.destination.unknown', "Unknown region destination: {$regionId}");
        }

        return $payload['sections'][$sectionIndex]['regions'][$regionId];
    }

    private function assertPosition(int $position, int $count, bool $allowEnd): void
    {
        $maximum = $allowEnd ? $count : max(0, $count - 1);
        if ($position < 0 || $position > $maximum) {
            throw new InvalidEditCommandException('command.position.invalid', "Position must be between 0 and {$maximum}");
        }
    }
}
