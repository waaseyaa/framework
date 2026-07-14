<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall\Fixtures;

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
                    ),
                ],
            ),
        ]);
    }
}
