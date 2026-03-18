<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class TaxonomyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'taxonomy_term',
            label: 'Taxonomy Term',
            class: Term::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
            group: 'taxonomy',
            fieldDefinitions: [
                'description' => [
                    'type' => 'text',
                    'label' => 'Description',
                    'description' => 'A description of the term.',
                    'weight' => 5,
                ],
                'weight' => [
                    'type' => 'integer',
                    'label' => 'Weight',
                    'description' => 'The weight of this term for ordering.',
                    'weight' => 10,
                ],
                'parent_id' => [
                    'type' => 'entity_reference',
                    'label' => 'Parent term',
                    'description' => 'The parent term for hierarchical vocabularies.',
                    'target_entity_type_id' => 'taxonomy_term',
                    'weight' => 15,
                ],
                'status' => [
                    'type' => 'boolean',
                    'label' => 'Published',
                    'description' => 'Whether the term is published.',
                    'weight' => 20,
                ],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'taxonomy_vocabulary',
            label: 'Vocabulary',
            class: Vocabulary::class,
            keys: ['id' => 'vid', 'label' => 'name'],
            group: 'taxonomy',
            fieldDefinitions: [
                'description' => [
                    'type' => 'text',
                    'label' => 'Description',
                    'description' => 'A description of the vocabulary.',
                    'weight' => 5,
                ],
                'weight' => [
                    'type' => 'integer',
                    'label' => 'Weight',
                    'description' => 'The weight of this vocabulary for ordering.',
                    'weight' => 10,
                ],
            ],
        ));
    }
}
