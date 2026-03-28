<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class EngagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $reactionTypes = $this->config['engagement']['reaction_types'] ?? Reaction::DEFAULT_REACTION_TYPES;

        $this->entityType(new EntityType(
            id: 'reaction',
            label: 'Reaction',
            class: Reaction::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'reaction_type'],
            group: 'engagement',
            fieldDefinitions: [
                'reaction_type' => ['type' => 'string', 'label' => 'Reaction Type', 'weight' => 0],
                'user_id' => ['type' => 'integer', 'label' => 'User ID', 'weight' => 1],
                'target_type' => ['type' => 'string', 'label' => 'Target Entity Type', 'weight' => 2],
                'target_id' => ['type' => 'integer', 'label' => 'Target Entity ID', 'weight' => 3],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'comment',
            label: 'Comment',
            class: Comment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'body'],
            group: 'engagement',
            fieldDefinitions: [
                'body' => ['type' => 'text_long', 'label' => 'Body', 'weight' => 0],
                'user_id' => ['type' => 'integer', 'label' => 'User ID', 'weight' => 1],
                'target_type' => ['type' => 'string', 'label' => 'Target Entity Type', 'weight' => 2],
                'target_id' => ['type' => 'integer', 'label' => 'Target Entity ID', 'weight' => 3],
                'status' => ['type' => 'boolean', 'label' => 'Published', 'weight' => 5, 'default' => 1],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'follow',
            label: 'Follow',
            class: Follow::class,
            keys: ['id' => 'fid', 'uuid' => 'uuid', 'label' => 'target_type'],
            group: 'engagement',
            fieldDefinitions: [
                'user_id' => ['type' => 'integer', 'label' => 'User ID', 'weight' => 0],
                'target_type' => ['type' => 'string', 'label' => 'Target Entity Type', 'weight' => 1],
                'target_id' => ['type' => 'integer', 'label' => 'Target Entity ID', 'weight' => 2],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
            ],
        ));
    }
}
