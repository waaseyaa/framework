<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'message_thread',
            label: 'Message Thread',
            class: MessageThread::class,
            keys: ['id' => 'mtid', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'messaging',
            fieldDefinitions: [
                'title' => ['type' => 'string', 'label' => 'Title', 'weight' => 0],
                'created_by' => ['type' => 'integer', 'label' => 'Created By', 'weight' => 1],
                'thread_type' => ['type' => 'string', 'label' => 'Thread Type', 'weight' => 2, 'default' => 'direct'],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
                'updated_at' => ['type' => 'timestamp', 'label' => 'Updated', 'weight' => 11],
                'last_message_at' => ['type' => 'timestamp', 'label' => 'Last Message At', 'weight' => 12, 'default' => 0],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'thread_participant',
            label: 'Thread Participant',
            class: ThreadParticipant::class,
            keys: ['id' => 'tpid', 'uuid' => 'uuid', 'label' => 'role'],
            group: 'messaging',
            fieldDefinitions: [
                'thread_id' => ['type' => 'integer', 'label' => 'Thread ID', 'weight' => 0],
                'user_id' => ['type' => 'integer', 'label' => 'User ID', 'weight' => 1],
                'thread_creator_id' => ['type' => 'integer', 'label' => 'Thread Creator ID', 'weight' => 2],
                'role' => ['type' => 'string', 'label' => 'Role', 'weight' => 3, 'default' => 'member'],
                'joined_at' => ['type' => 'timestamp', 'label' => 'Joined', 'weight' => 10],
                'last_read_at' => ['type' => 'timestamp', 'label' => 'Last Read', 'weight' => 11, 'default' => 0],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'thread_message',
            label: 'Thread Message',
            class: ThreadMessage::class,
            keys: ['id' => 'tmid', 'uuid' => 'uuid', 'label' => 'body'],
            group: 'messaging',
            fieldDefinitions: [
                'thread_id' => ['type' => 'integer', 'label' => 'Thread ID', 'weight' => 0],
                'sender_id' => ['type' => 'integer', 'label' => 'Sender ID', 'weight' => 1],
                'body' => ['type' => 'text_long', 'label' => 'Body', 'weight' => 2],
                'status' => ['type' => 'boolean', 'label' => 'Status', 'weight' => 3, 'default' => 1],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
                'edited_at' => ['type' => 'timestamp', 'label' => 'Edited At', 'weight' => 11, 'default' => null],
                'deleted_at' => ['type' => 'timestamp', 'label' => 'Deleted At', 'weight' => 12, 'default' => null],
            ],
        ));
    }
}
