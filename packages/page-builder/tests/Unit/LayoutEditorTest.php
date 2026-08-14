<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Command\AddBlock;
use Waaseyaa\PageBuilder\Command\AddSection;
use Waaseyaa\PageBuilder\Command\ChangeSectionLayout;
use Waaseyaa\PageBuilder\Command\ConfigureBlock;
use Waaseyaa\PageBuilder\Command\DuplicateBlock;
use Waaseyaa\PageBuilder\Command\DuplicateSection;
use Waaseyaa\PageBuilder\Command\MoveBlock;
use Waaseyaa\PageBuilder\Command\MoveSection;
use Waaseyaa\PageBuilder\Command\RemoveBlock;
use Waaseyaa\PageBuilder\Command\RemoveSection;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Editor\Exception\InvalidEditCommandException;
use Waaseyaa\PageBuilder\Editor\Exception\StaleDocumentFingerprintException;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

final class LayoutEditorTest extends TestCase
{
    #[Test]
    public function addConfigureMoveAndRemoveUseStableInstanceIds(): void
    {
        $editor = $this->editor();
        $original = $this->document();
        $fingerprint = $editor->fingerprint($original);

        $added = $editor->apply($original, $fingerprint, new AddBlock(
            sectionId: 'sec_content',
            regionId: 'main',
            position: 1,
            block: [
                'id' => 'blk_second',
                'type' => 'rich_text',
                'version' => 1,
                'config' => ['html' => '<p>Second</p>'],
            ],
        ));
        self::assertSame(['blk_first', 'blk_second'], $this->blockIds($added->document(), 'main'));
        self::assertNotSame($fingerprint, $added->fingerprint());
        self::assertSame('block.added', $added->summary()['code']);

        $configured = $editor->apply($added->document(), $added->fingerprint(), new ConfigureBlock(
            blockId: 'blk_second',
            config: ['html' => '<p>Changed</p>'],
        ));
        self::assertSame(
            ['html' => '<p>First</p>'],
            $configured->document()->sections()[0]['regions']['main'][0]['config'],
            'Configuring one stable block must preserve untouched block data.',
        );

        $moved = $editor->apply($configured->document(), $configured->fingerprint(), new MoveBlock(
            blockId: 'blk_second',
            destinationSectionId: 'sec_content',
            destinationRegionId: 'sidebar',
            position: 0,
        ));
        self::assertSame(['blk_first'], $this->blockIds($moved->document(), 'main'));
        self::assertSame(['blk_second'], $this->blockIds($moved->document(), 'sidebar'));

        $removed = $editor->apply($moved->document(), $moved->fingerprint(), new RemoveBlock('blk_first'));
        self::assertSame([], $this->blockIds($removed->document(), 'main'));
        self::assertSame(['blk_second'], $this->blockIds($removed->document(), 'sidebar'));
    }

    #[Test]
    public function staleFingerprintRefusesBeforeApplyingTheCommand(): void
    {
        $document = $this->document();

        $this->expectException(StaleDocumentFingerprintException::class);

        $this->editor()->apply($document, str_repeat('0', 64), new RemoveBlock('blk_first'));
    }

    #[Test]
    public function unknownTargetsAndInvalidResultDocumentsAreTypedFailures(): void
    {
        $editor = $this->editor();
        $document = $this->document();

        try {
            $editor->apply($document, $editor->fingerprint($document), new RemoveBlock('blk_missing'));
            self::fail('Unknown block target was accepted.');
        } catch (InvalidEditCommandException $exception) {
            self::assertSame('command.target.unknown', $exception->machineCode);
        }

        try {
            $editor->apply($document, $editor->fingerprint($document), new AddBlock(
                sectionId: 'sec_content',
                regionId: 'main',
                position: 1,
                block: [
                    'id' => 'blk_first',
                    'type' => 'rich_text',
                    'version' => 1,
                    'config' => ['html' => '<p>Duplicate</p>'],
                ],
            ));
            self::fail('A command producing duplicate instance IDs was accepted.');
        } catch (InvalidEditCommandException $exception) {
            self::assertSame('command.result.invalid', $exception->machineCode);
            self::assertSame(['document.instance_id.duplicate'], array_column($exception->violations, 'code'));
        }
    }

    #[Test]
    public function duplicateAndSectionCommandsPreserveStableContent(): void
    {
        $editor = $this->editor();
        $result = $editor->apply($this->document(), $editor->fingerprint($this->document()), new DuplicateBlock(
            sourceBlockId: 'blk_first',
            duplicateBlockId: 'blk_copy',
        ));
        self::assertSame(['blk_first', 'blk_copy'], $this->blockIds($result->document(), 'main'));
        self::assertSame(
            $result->document()->sections()[0]['regions']['main'][0]['config'],
            $result->document()->sections()[0]['regions']['main'][1]['config'],
        );

        $result = $editor->apply($result->document(), $result->fingerprint(), new AddSection(0, [
            'id' => 'sec_intro',
            'layout' => ['id' => 'one_column', 'version' => 1],
            'regions' => ['main' => []],
        ]));
        self::assertSame(['sec_intro', 'sec_content'], array_column($result->document()->sections(), 'id'));

        $result = $editor->apply($result->document(), $result->fingerprint(), new MoveSection('sec_intro', 1));
        self::assertSame(['sec_content', 'sec_intro'], array_column($result->document()->sections(), 'id'));

        $result = $editor->apply($result->document(), $result->fingerprint(), new RemoveSection('sec_intro'));
        self::assertSame(['sec_content'], array_column($result->document()->sections(), 'id'));
        self::assertSame(['blk_first', 'blk_copy'], $this->blockIds($result->document(), 'main'));
    }

    #[Test]
    public function duplicateSectionRequiresFreshValidatedInstanceIds(): void
    {
        $editor = $this->editor();
        $document = $this->document();

        $result = $editor->apply($document, $editor->fingerprint($document), new DuplicateSection(
            sourceSectionId: 'sec_content',
            duplicateSectionId: 'sec_copy',
            duplicateBlockIds: ['blk_first' => 'blk_copy'],
        ));

        self::assertSame(['sec_content', 'sec_copy'], array_column($result->document()->sections(), 'id'));
        self::assertSame(['blk_copy'], $this->blockIds($result->document(), 'main', section: 1));
        self::assertSame(
            $result->document()->sections()[0]['regions']['main'][0]['config'],
            $result->document()->sections()[1]['regions']['main'][0]['config'],
        );
        self::assertSame(['code' => 'section.duplicated', 'target' => 'sec_copy'], $result->summary());
    }

    #[Test]
    public function removingTheLastSectionFailsClosed(): void
    {
        $editor = $this->editor();
        $document = $this->document();

        try {
            $editor->apply(
                $document,
                $editor->fingerprint($document),
                new RemoveSection('sec_content'),
            );
            self::fail('The final page-builder section was removed.');
        } catch (InvalidEditCommandException $exception) {
            self::assertSame('command.section.last_required', $exception->machineCode);
        }
    }

    #[Test]
    public function duplicateSectionRequiresAnExactSourceToDuplicateIdMap(): void
    {
        $editor = $this->editor();
        $document = $this->document();

        foreach ([[], ['blk_unknown' => 'blk_copy']] as $duplicateBlockIds) {
            try {
                $editor->apply($document, $editor->fingerprint($document), new DuplicateSection(
                    sourceSectionId: 'sec_content',
                    duplicateSectionId: 'sec_copy',
                    duplicateBlockIds: $duplicateBlockIds,
                ));
                self::fail('A section duplication with an inexact block ID map was accepted.');
            } catch (InvalidEditCommandException $exception) {
                self::assertSame('command.duplicate_section.block_ids_mismatch', $exception->machineCode);
            }
        }
    }

    #[Test]
    public function changingLayoutAddsDeclaredRegionsButNeverDropsContent(): void
    {
        $editor = $this->editor();
        $oneColumn = LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_content',
                'layout' => ['id' => 'one_column', 'version' => 1],
                'regions' => ['main' => [[
                    'id' => 'blk_first',
                    'type' => 'rich_text',
                    'version' => 1,
                    'config' => ['html' => '<p>First</p>'],
                ]]],
            ]],
        ]);

        $changed = $editor->apply($oneColumn, $editor->fingerprint($oneColumn), new ChangeSectionLayout(
            sectionId: 'sec_content',
            layoutId: 'content_sidebar',
            layoutVersion: 1,
        ));
        self::assertSame(['main', 'sidebar'], array_keys($changed->document()->sections()[0]['regions']));
        self::assertSame(['blk_first'], $this->blockIds($changed->document(), 'main'));

        $withSidebarContent = $editor->apply($changed->document(), $changed->fingerprint(), new AddBlock(
            sectionId: 'sec_content',
            regionId: 'sidebar',
            position: 0,
            block: [
                'id' => 'blk_sidebar',
                'type' => 'rich_text',
                'version' => 1,
                'config' => ['html' => '<p>Keep me</p>'],
            ],
        ));

        try {
            $editor->apply($withSidebarContent->document(), $withSidebarContent->fingerprint(), new ChangeSectionLayout(
                sectionId: 'sec_content',
                layoutId: 'one_column',
                layoutVersion: 1,
            ));
            self::fail('Layout change silently dropped a populated region.');
        } catch (InvalidEditCommandException $exception) {
            self::assertSame('command.layout.content_would_be_removed', $exception->machineCode);
        }
    }

    private function editor(): LayoutEditor
    {
        $registry = new DefinitionRegistry();
        $registry->registerBlock(new BlockDefinition(
            id: 'rich_text',
            version: 1,
            label: 'Rich text',
            renderer: 'content.rich_text',
            configSchema: [
                'type' => 'object',
                'required' => ['html'],
                'additionalProperties' => false,
                'properties' => ['html' => ['type' => 'string']],
            ],
        ));
        $registry->registerLayout(new LayoutDefinition(
            id: 'content_sidebar',
            version: 1,
            regions: ['main', 'sidebar'],
            requiredRegions: ['main', 'sidebar'],
            allowedBlocks: ['rich_text'],
        ));
        $registry->registerLayout(new LayoutDefinition(
            id: 'one_column',
            version: 1,
            regions: ['main'],
            requiredRegions: ['main'],
            allowedBlocks: ['rich_text'],
        ));
        $registry->registerTemplate(new TemplateDefinition(
            id: 'standard',
            version: 1,
            allowedLayouts: ['content_sidebar', 'one_column'],
            allowedBlocks: ['rich_text'],
        ));

        return new LayoutEditor(new CanonicalLayoutCodec(), new LayoutValidator($registry), $registry);
    }

    private function document(): LayoutDocument
    {
        return LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_content',
                'layout' => ['id' => 'content_sidebar', 'version' => 1],
                'regions' => [
                    'main' => [[
                        'id' => 'blk_first',
                        'type' => 'rich_text',
                        'version' => 1,
                        'config' => ['html' => '<p>First</p>'],
                    ]],
                    'sidebar' => [],
                ],
            ]],
        ]);
    }

    /** @return list<string> */
    private function blockIds(LayoutDocument $document, string $region, int $section = 0): array
    {
        return array_column($document->sections()[$section]['regions'][$region], 'id');
    }
}
