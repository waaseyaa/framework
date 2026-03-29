<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class PathServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'path_alias',
            label: 'Path Alias',
            description: 'URL aliases for human-readable paths',
            class: PathAlias::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'alias', 'langcode' => 'langcode'],
            group: 'structure',
            fieldDefinitions: [
                'path' => [
                    'type' => 'string',
                    'label' => 'System path',
                    'description' => 'Internal path such as /node/1.',
                ],
                'alias' => [
                    'type' => 'string',
                    'label' => 'Alias',
                    'description' => 'Public alias path.',
                ],
                'langcode' => [
                    'type' => 'string',
                    'label' => 'Language',
                    'description' => 'Alias language code.',
                ],
                'status' => [
                    'type' => 'boolean',
                    'label' => 'Published',
                    'description' => 'Whether this alias is active.',
                    'default' => 1,
                ],
            ],
        ));
    }
}
