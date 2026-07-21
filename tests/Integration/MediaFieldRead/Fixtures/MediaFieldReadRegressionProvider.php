<?php

declare(strict_types=1);

namespace MediaReadRegression;

use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MediaFieldReadRegressionProvider extends ServiceProvider
{
    public function register(): void
    {
        $registry = $this->resolve(FieldDefinitionRegistryInterface::class);
        $registry->registerBundleFields('media', 'members_document', [
            new FieldDefinition(
                name: 'description',
                type: 'text',
                targetEntityTypeId: 'media',
                targetBundle: 'members_document',
                stored: FieldStorage::Data,
                read: FieldReadLevel::Protected,
            ),
        ]);
    }
}
