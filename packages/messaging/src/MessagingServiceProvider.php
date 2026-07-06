<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Messaging\EventSubscriber\ThreadParticipantBootstrapSubscriber;

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
        // Seed the creating account as the first thread_participant so an
        // ordinary non-admin can populate a thread they just created (#1915,
        // R16 — see ThreadParticipantBootstrapSubscriber for the full story).
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $entityTypeManager = $this->resolveOptional(EntityTypeManagerInterface::class);
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
