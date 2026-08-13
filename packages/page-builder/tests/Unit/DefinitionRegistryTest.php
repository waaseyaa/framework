<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\Exception\DuplicateDefinitionException;
use Waaseyaa\PageBuilder\Definition\Exception\UnknownDefinitionException;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;

final class DefinitionRegistryTest extends TestCase
{
    #[Test]
    public function exportsAStableClientSafeManifestInIdentityOrder(): void
    {
        $registry = new DefinitionRegistry();
        $registry->registerBlock(new BlockDefinition('z_block', 1, 'Zed', 'content.zed', ['type' => 'object']));
        $registry->registerBlock(new BlockDefinition('a_block', 2, 'Alpha', 'content.alpha', ['type' => 'object']));
        $registry->registerLayout(new LayoutDefinition('two_column', 1, ['main', 'aside'], ['main'], ['a_block']));
        $registry->registerTemplate(new TemplateDefinition('standard', 1, ['two_column'], ['a_block']));

        self::assertSame([
            'blocks' => [
                [
                    'id' => 'a_block',
                    'version' => 2,
                    'label' => 'Alpha',
                    'renderer' => 'content.alpha',
                    'config_schema' => ['type' => 'object'],
                ],
                [
                    'id' => 'z_block',
                    'version' => 1,
                    'label' => 'Zed',
                    'renderer' => 'content.zed',
                    'config_schema' => ['type' => 'object'],
                ],
            ],
            'layouts' => [[
                'id' => 'two_column',
                'version' => 1,
                'regions' => ['main', 'aside'],
                'required_regions' => ['main'],
                'allowed_blocks' => ['a_block'],
            ]],
            'templates' => [[
                'id' => 'standard',
                'version' => 1,
                'allowed_layouts' => ['two_column'],
                'allowed_blocks' => ['a_block'],
            ]],
        ], $registry->manifest());
    }

    #[Test]
    public function lookupRequiresTheExactDefinitionVersion(): void
    {
        $registry = new DefinitionRegistry();
        $definition = new BlockDefinition(
            id: 'rich_text',
            version: 1,
            label: 'Rich text',
            renderer: 'content.rich_text',
            configSchema: ['type' => 'object'],
        );
        $registry->registerBlock($definition);

        self::assertSame($definition, $registry->block('rich_text', 1));

        $this->expectException(UnknownDefinitionException::class);
        $registry->block('rich_text', 2);
    }

    #[Test]
    public function duplicateIdentityAndVersionFailAtRegistration(): void
    {
        $registry = new DefinitionRegistry();
        $definition = new BlockDefinition(
            id: 'rich_text',
            version: 1,
            label: 'Rich text',
            renderer: 'content.rich_text',
            configSchema: ['type' => 'object'],
        );
        $registry->registerBlock($definition);

        $this->expectException(DuplicateDefinitionException::class);
        $registry->registerBlock($definition);
    }

    #[Test]
    public function layoutAndTemplateDefinitionsUseTheSameExactVersionContract(): void
    {
        $registry = new DefinitionRegistry();
        $layout = new LayoutDefinition('one_column', 1, ['main'], ['main'], ['rich_text']);
        $template = new TemplateDefinition('standard', 1, ['one_column'], ['rich_text']);
        $registry->registerLayout($layout);
        $registry->registerTemplate($template);

        self::assertSame($layout, $registry->layout('one_column', 1));
        self::assertSame($template, $registry->template('standard', 1));

        $this->expectException(DuplicateDefinitionException::class);
        $registry->registerLayout($layout);
    }

    #[Test]
    public function unknownTemplateFailsClosed(): void
    {
        $this->expectException(UnknownDefinitionException::class);
        new DefinitionRegistry()->template('standard', 1);
    }
}
