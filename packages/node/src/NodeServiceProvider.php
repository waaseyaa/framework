<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class NodeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            group: 'content',
            fieldDefinitions: [
                'title' => [
                    'type' => 'string',
                    'label' => 'Title',
                    'description' => 'The title of the content.',
                    'required' => true,
                    'weight' => 0,
                ],
                'type' => [
                    'type' => 'string',
                    'label' => 'Content type',
                    'description' => 'The bundle (content type) of this node.',
                    'required' => true,
                    'readOnly' => true,
                    'weight' => 1,
                ],
                'slug' => [
                    'type' => 'string',
                    'label' => 'Slug',
                    'description' => 'The URL-friendly identifier for this content.',
                    'required' => true,
                    'weight' => 2,
                ],
                'status' => [
                    'type' => 'boolean',
                    'label' => 'Published',
                    'description' => 'Whether the content is published.',
                    'weight' => 10,
                    'default' => 1,
                ],
                'promote' => [
                    'type' => 'boolean',
                    'label' => 'Promoted to front page',
                    'description' => 'Whether the content is promoted to the front page.',
                    'weight' => 11,
                    'default' => 0,
                ],
                'sticky' => [
                    'type' => 'boolean',
                    'label' => 'Sticky at top of lists',
                    'description' => 'Whether the content is sticky at the top of lists.',
                    'weight' => 12,
                    'default' => 0,
                ],
                'uid' => [
                    'type' => 'entity_reference',
                    'label' => 'Author',
                    'description' => 'The user who authored this content.',
                    'target_entity_type_id' => 'user',
                    'weight' => 20,
                ],
                'created' => [
                    'type' => 'timestamp',
                    'label' => 'Authored on',
                    'description' => 'The date and time the content was created.',
                    'weight' => 30,
                ],
                'changed' => [
                    'type' => 'timestamp',
                    'label' => 'Last updated',
                    'description' => 'The date and time the content was last updated.',
                    'weight' => 31,
                ],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: NodeType::class,
            keys: ['id' => 'type', 'label' => 'name'],
            group: 'content',
        ));
    }
}
