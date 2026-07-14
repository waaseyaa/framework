<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class TaxonomyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            Term::class,
            group: 'taxonomy',
            bundleEntityType: 'taxonomy_vocabulary',
        ));

        // Vocabulary is a configuration entity (ConfigEntityBase). Field-attribute
        // reflection does not apply; declare field definitions explicitly so the
        // admin SPA and API can render description/weight as editable fields.
        $this->entityType(new EntityType(
            id: 'taxonomy_vocabulary',
            label: 'Vocabulary',
            description: 'Term groupings that define classification systems',
            class: Vocabulary::class,
            keys: ['id' => 'vid', 'label' => 'name'],
            group: 'taxonomy',
            _fieldDefinitions: [
                'description' => new FieldDefinition(
                    name: 'description',
                    type: 'text',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Description',
                    description: 'A description of the vocabulary.',
                    settings: ['weight' => 5],
                ),
                'weight' => new FieldDefinition(
                    name: 'weight',
                    type: 'integer',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Weight',
                    description: 'Sort order for this vocabulary.',
                    settings: ['weight' => 10],
                ),
            ],
        ));
    }

    public function boot(): void
    {
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$dispatcher instanceof EventDispatcherInterface || !$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new TermHierarchyGuard($entityTypeManager),
        );
    }
}
