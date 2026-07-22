<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Database\DatabaseInterface;
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
        $term = EntityType::fromClass(Term::class);
        $this->entityType(new EntityType(
            id: $term->id(),
            label: $term->getLabel(),
            class: $term->getClass(),
            keys: $term->getKeys(),
            group: 'taxonomy',
            bundleEntityType: 'taxonomy_vocabulary',
            description: $term->getDescription(),
            api: true,
            _fieldDefinitions: $term->getFieldDefinitions(),
            _foreignKeys: [[
                'name' => VocabularyReferenceConstraint::NAME,
                'columns' => ['vid'],
                'table' => 'taxonomy_vocabulary',
                'references' => ['vid'],
                'options' => ['onDelete' => 'RESTRICT', 'onUpdate' => 'CASCADE'],
            ]],
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
            api: true,
            _fieldDefinitions: [
                'vid' => new FieldDefinition(
                    name: 'vid',
                    type: 'string',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Machine name',
                    description: 'Stable vocabulary identifier.',
                    readOnly: true,
                    settings: ['weight' => 0],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'name' => new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Name',
                    description: 'The vocabulary display name.',
                    settings: ['weight' => 1],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'description' => new FieldDefinition(
                    name: 'description',
                    type: 'text',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Description',
                    description: 'A description of the vocabulary.',
                    settings: ['weight' => 5],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
                'weight' => new FieldDefinition(
                    name: 'weight',
                    type: 'integer',
                    targetEntityTypeId: 'taxonomy_vocabulary',
                    label: 'Weight',
                    description: 'Sort order for this vocabulary.',
                    settings: ['weight' => 10],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
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
        $database = $this->resolveOptional(DatabaseInterface::class);
        if ($database instanceof DatabaseInterface) {
            new VocabularyReferenceConstraint($database)->ensure();
        }
    }
}
