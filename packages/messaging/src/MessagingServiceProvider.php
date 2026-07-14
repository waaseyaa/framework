<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Messaging\EventSubscriber\ThreadParticipantBootstrapSubscriber;
use Waaseyaa\Messaging\Schema\ThreadParticipantSchema;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Type metadata (id, label, keys, fields) lives on each entity class
        // via #[ContentEntityType], #[ContentEntityKeys], and #[Field] attributes.
        $this->entityType(EntityType::fromClass(MessageThread::class, group: 'messaging'));
        $this->entityType(EntityType::fromClass(ThreadParticipant::class, group: 'messaging'));
        $this->entityType(EntityType::fromClass(ThreadMessage::class, group: 'messaging'));
    }

    public function boot(): void
    {
        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        $database = $this->resolveOptional(\Waaseyaa\Database\DatabaseInterface::class);
        if ($database instanceof DBALDatabase && $entityTypeManager instanceof EntityTypeManagerInterface) {
            // Repository resolution materializes the generic base table first;
            // the package schema then heals the two identity columns and key.
            $entityTypeManager->getRepository('thread_participant');
            new ThreadParticipantSchema($database)->ensureTable();
        }

        // Seed the creating account as the first thread_participant so an
        // ordinary non-admin can populate a thread they just created (#1915,
        // R16 — see ThreadParticipantBootstrapSubscriber for the full story).
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $accountContext = $this->resolveOptional(AccountContextInterface::class);
        $resolvedContext = $accountContext instanceof AccountContextInterface ? $accountContext : null;

        $logger = $this->resolveOptional(LoggerInterface::class);
        $resolvedLogger = $logger instanceof LoggerInterface ? $logger : null;

        $dispatcher->addSubscriber(new ThreadParticipantBootstrapSubscriber(
            $entityTypeManager,
            $resolvedContext,
            $resolvedLogger,
        ));
    }
}
