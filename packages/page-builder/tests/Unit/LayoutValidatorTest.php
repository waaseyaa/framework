<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

final class LayoutValidatorTest extends TestCase
{
    #[Test]
    public function validDocumentProducesNoViolations(): void
    {
        self::assertSame([], $this->validator()->validate($this->document())->violations());
    }

    #[Test]
    public function unknownRegionsAndDuplicateInstanceIdsFailClosed(): void
    {
        $payload = $this->document()->toArray();
        $payload['sections'][0]['regions']['sidebar'] = [[
            'id' => 'blk_shared',
            'type' => 'rich_text',
            'version' => 1,
            'config' => ['html' => '<p>One</p>'],
        ]];
        $payload['sections'][0]['regions']['main'][0]['id'] = 'blk_shared';

        $violations = $this->validator()->validate(LayoutDocument::fromArray($payload))->violations();

        self::assertSame(
            ['document.instance_id.duplicate', 'layout.region.unknown'],
            array_column($violations, 'code'),
        );
    }

    #[Test]
    public function blockConfigurationMustMatchItsRegisteredSchema(): void
    {
        $payload = $this->document()->toArray();
        $payload['sections'][0]['regions']['main'][0]['config']['html'] = 42;

        $violations = $this->validator()->validate(LayoutDocument::fromArray($payload))->violations();

        self::assertSame(['block.config.invalid'], array_column($violations, 'code'));
        self::assertSame('/sections/0/regions/main/0/config', $violations[0]['pointer']);
    }

    #[Test]
    public function unknownNestedAuthorityFieldsFailClosed(): void
    {
        $payload = $this->document()->toArray();
        $payload['sections'][0]['regions']['main'][0]['runtime_component'] = 'unreviewed';

        $violations = $this->validator()->validate(LayoutDocument::fromArray($payload))->violations();

        self::assertSame(['block.field.unknown'], array_column($violations, 'code'));
        self::assertSame('/sections/0/regions/main/0/runtime_component', $violations[0]['pointer']);
    }

    #[Test]
    public function unknownTemplateReturnsOneStableViolation(): void
    {
        $payload = $this->document()->toArray();
        $payload['template']['id'] = 'missing';

        self::assertSame(
            ['template.definition.unknown'],
            array_column($this->validator()->validate(LayoutDocument::fromArray($payload))->violations(), 'code'),
        );
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): void, string}> */
    public static function invalidStructures(): iterable
    {
        yield 'section authority' => [static function (array &$payload): void { $payload['sections'][0]['future'] = true; }, 'section.field.unknown'];
        yield 'section id' => [static function (array &$payload): void { $payload['sections'][0]['id'] = 'bad'; }, 'document.instance_id.invalid'];
        yield 'layout reference' => [static function (array &$payload): void { $payload['sections'][0]['layout'] = 'one_column'; }, 'layout.reference.invalid'];
        yield 'layout authority' => [static function (array &$payload): void { $payload['sections'][0]['layout']['future'] = 1; }, 'layout.field.unknown'];
        yield 'unknown layout' => [static function (array &$payload): void { $payload['sections'][0]['layout']['id'] = 'missing'; }, 'layout.definition.unknown'];
        yield 'invalid regions' => [static function (array &$payload): void { $payload['sections'][0]['regions'] = []; }, 'layout.regions.invalid'];
        yield 'missing region' => [static function (array &$payload): void { $payload['sections'][0]['regions'] = ['other' => []]; }, 'layout.region.required'];
        yield 'invalid block list' => [static function (array &$payload): void { $payload['sections'][0]['regions']['main'] = ['bad' => true]; }, 'layout.region.blocks.invalid'];
        yield 'scalar block' => [static function (array &$payload): void { $payload['sections'][0]['regions']['main'] = ['bad']; }, 'block.invalid'];
        yield 'block reference' => [static function (array &$payload): void { unset($payload['sections'][0]['regions']['main'][0]['type']); }, 'block.reference.invalid'];
        yield 'unknown block' => [static function (array &$payload): void { $payload['sections'][0]['regions']['main'][0]['type'] = 'missing'; }, 'block.definition.unknown'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStructures')]
    public function malformedLayoutStructuresFailClosed(\Closure $mutate, string $expectedCode): void
    {
        $payload = $this->document()->toArray();
        $mutate($payload);
        self::assertContains(
            $expectedCode,
            array_column($this->validator()->validate(LayoutDocument::fromArray($payload))->violations(), 'code'),
        );
    }

    private function validator(): LayoutValidator
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
            id: 'one_column',
            version: 1,
            regions: ['main'],
            requiredRegions: ['main'],
            allowedBlocks: ['rich_text'],
        ));
        $registry->registerTemplate(new TemplateDefinition(
            id: 'standard',
            version: 1,
            allowedLayouts: ['one_column'],
            allowedBlocks: ['rich_text'],
        ));

        return new LayoutValidator($registry);
    }

    private function document(): LayoutDocument
    {
        return LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_content',
                'layout' => ['id' => 'one_column', 'version' => 1],
                'regions' => [
                    'main' => [[
                        'id' => 'blk_content',
                        'type' => 'rich_text',
                        'version' => 1,
                        'config' => ['html' => '<p>Boozhoo</p>'],
                    ]],
                ],
            ]],
        ]);
    }
}
