<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall\Fixtures;

use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Migration\ContentModel\ContentModel;
use Waaseyaa\Migration\ContentModel\ContentTypeModel;
use Waaseyaa\Migration\ContentModel\DerivesContentModelInterface;

/** @internal Fresh-install cutover smoke fixture. */
final class CutoverContentModelProvider extends ServiceProvider implements DerivesContentModelInterface
{
    public function register(): void {}

    public function deriveContentModel(): ContentModel
    {
        return new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: 'node',
                bundle: 'cutover_page',
                label: 'Cutover page',
                fields: [
                    new FieldDefinition(
                        name: 'body',
                        type: 'text_long',
                        targetEntityTypeId: 'node',
                        targetBundle: 'cutover_page',
                        read: FieldReadLevel::Public,
                    ),
                ],
            ),
            new ContentTypeModel(
                entityTypeId: 'node',
                bundle: 'page',
                label: 'Page',
                fields: $this->commonFields('page'),
            ),
            new ContentTypeModel(
                entityTypeId: 'node',
                bundle: 'post',
                label: 'News',
                fields: $this->commonFields('post'),
            ),
            new ContentTypeModel(
                entityTypeId: 'node',
                bundle: 'tribe_events',
                label: 'Event',
                fields: [
                    ...$this->commonFields('tribe_events'),
                    new FieldDefinition(
                        name: 'event_start',
                        type: 'string',
                        settings: ['weight' => 10],
                        targetEntityTypeId: 'node',
                        targetBundle: 'tribe_events',
                        label: 'Event start',
                        read: FieldReadLevel::Public,
                    ),
                    new FieldDefinition(
                        name: 'event_end',
                        type: 'string',
                        settings: ['weight' => 11],
                        targetEntityTypeId: 'node',
                        targetBundle: 'tribe_events',
                        label: 'Event end',
                        read: FieldReadLevel::Public,
                    ),
                ],
            ),
        ]);
    }

    /** @return list<FieldDefinition> */
    private function commonFields(string $bundle): array
    {
        return [
            new FieldDefinition(
                name: 'body',
                type: 'text_long',
                targetEntityTypeId: 'node',
                targetBundle: $bundle,
                read: FieldReadLevel::Public,
            ),
        ];
    }
}
