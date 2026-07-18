<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Migration\ContentModel\ContentModel;
use Waaseyaa\Migration\ContentModel\ContentTypeModel;
use Waaseyaa\Migration\ContentModel\DerivesContentModelInterface;

/** @internal Fresh-install subprocess fixture for issue #1982. */
final class FreshInstallContentModelProvider extends ServiceProvider implements DerivesContentModelInterface
{
    public const string ENTITY_TYPE = 'fresh_install_page';
    public const string BUNDLE_TYPE = 'fresh_install_page_type';
    public const string BUNDLE = 'page';

    public function register(): void
    {
        $this->entityType(new EntityType(
            id: self::ENTITY_TYPE,
            label: 'Fresh-install page',
            class: FreshInstallPage::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'page_type'],
            bundleEntityType: self::BUNDLE_TYPE,
        ));
        $this->entityType(new EntityType(
            id: self::BUNDLE_TYPE,
            label: 'Fresh-install page type',
            class: FreshInstallPageType::class,
            keys: ['id' => 'id', 'label' => 'label'],
        ));
    }

    public function deriveContentModel(): ContentModel
    {
        return new ContentModel(types: [
            new ContentTypeModel(
                entityTypeId: self::ENTITY_TYPE,
                bundle: self::BUNDLE,
                label: 'Page',
                fields: [
                    new FieldDefinition(
                        name: 'body',
                        type: 'text_long',
                        targetEntityTypeId: self::ENTITY_TYPE,
                        targetBundle: self::BUNDLE,
                        read: FieldReadLevel::Public,
                    ),
                ],
            ),
        ]);
    }
}
