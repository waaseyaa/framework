<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class NoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: Note::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'content',
            // Field definitions mirror defaults/core.note.yaml — keep in sync.
            fieldDefinitions: [
                'title' => [
                    'type' => 'string',
                    'label' => 'Title',
                    'description' => 'Note title.',
                    'required' => true,
                    'weight' => 0,
                ],
                'body' => [
                    'type' => 'text',
                    'label' => 'Body',
                    'description' => 'Note body. Plain text or Markdown.',
                    'required' => false,
                    'weight' => 1,
                ],
            ],
        ));
    }
}
