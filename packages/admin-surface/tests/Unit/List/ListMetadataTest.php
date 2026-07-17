<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\List;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\List\ListMetadata;

final class ListMetadataTest extends TestCase
{
    #[Test]
    public function valid_declaration_is_normalized_as_inert_closed_metadata(): void
    {
        $metadata = ListMetadata::fromArray([
            'columns' => [
                ['field' => 'kind', 'label' => 'Kind', 'sortable' => false, 'formatter' => 'enum', 'valueLabels' => ['news' => '<News>']],
                ['field' => 'changed', 'label' => 'Changed', 'sortable' => true, 'formatter' => 'datetime'],
            ],
            'search' => ['field' => 'title', 'operator' => 'STARTS_WITH', 'label' => 'Search titles', 'description' => 'Type the beginning of a title.'],
            'filters' => [[
                'field' => 'kind',
                'operator' => 'EQUALS',
                'label' => 'Content type',
                'options' => [['value' => 'news', 'label' => '<News>']],
                'default' => 'news',
            ]],
            'sorts' => [
                ['field' => 'title', 'direction' => 'ASC', 'label' => 'Title (A–Z)'],
                ['field' => 'changed', 'direction' => 'DESC', 'label' => 'Recently changed'],
            ],
            'defaultSort' => ['field' => 'changed', 'direction' => 'DESC'],
        ]);

        self::assertNotNull($metadata);
        self::assertSame('<News>', $metadata->toArray()['columns'][0]['valueLabels']['news']);
        self::assertSame('STARTS_WITH', $metadata->toArray()['search']['operator']);
        self::assertSame(['field' => 'changed', 'direction' => 'DESC'], $metadata->toArray()['defaultSort']);
    }

    #[Test]
    public function arbitrary_formatter_and_executable_metadata_fail_closed(): void
    {
        foreach (['html', 'javascript', 'template', 'php_callback', 'url', '%Y-%m-%d'] as $formatter) {
            self::assertNull(ListMetadata::fromArray([
                'columns' => [[
                    'field' => 'title',
                    'label' => 'Title',
                    'sortable' => false,
                    'formatter' => $formatter,
                ]],
            ]), $formatter);
        }

        self::assertNull(ListMetadata::fromArray([
            'columns' => [['field' => 'title', 'label' => 'Title', 'sortable' => false, 'formatter' => 'text', 'callback' => 'system']],
        ]));
    }

    #[Test]
    public function malformed_declarations_return_null_without_throwing(): void
    {
        foreach ([
            'not-an-object',
            ['columns' => 'not-a-list'],
            ['columns' => [['field' => '', 'label' => 'Title', 'sortable' => false, 'formatter' => 'text']]],
            ['search' => ['field' => 'title', 'operator' => 'REGEX', 'label' => 'Search']],
            ['filters' => [['field' => 'kind', 'operator' => 'REGEX', 'label' => 'Kind']]],
            ['sorts' => [['field' => 'title', 'direction' => 'SIDEWAYS', 'label' => 'Title']]],
            ['sorts' => [['field' => 'title', 'direction' => 'ASC', 'label' => 'Title']], 'defaultSort' => ['field' => 'changed', 'direction' => 'DESC']],
            [
                'columns' => [['field' => 'title', 'label' => 'Title', 'sortable' => true, 'formatter' => 'text']],
                'sorts' => [['field' => 'changed', 'direction' => 'DESC', 'label' => 'Recently changed']],
            ],
        ] as $raw) {
            self::assertNull(ListMetadata::fromArray($raw));
        }
    }
}
