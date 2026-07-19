<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class RelationshipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(AuthorizedRelationshipTraversal::class, function (): AuthorizedRelationshipTraversal {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            $database = $this->resolve(DatabaseInterface::class);
            $accessHandler = $this->resolve(EntityAccessHandler::class);
            $fieldReadScope = $this->resolve(AccountFieldReadScopeInterface::class);

            assert($entityTypeManager instanceof EntityTypeManagerInterface);
            assert($database instanceof DatabaseInterface);
            assert($accessHandler instanceof EntityAccessHandler);
            assert($fieldReadScope instanceof AccountFieldReadScopeInterface);

            return new AuthorizedRelationshipTraversal(
                $entityTypeManager,
                $database,
                $accessHandler,
                $fieldReadScope,
            );
        });

        $this->entityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            description: 'Connections between entities for cross-referencing',
            class: Relationship::class,
            keys: [
                'id' => 'rid',
                'uuid' => 'uuid',
                'label' => 'relationship_type',
                'bundle' => 'relationship_type',
            ],
            group: 'content',
            api: true,
        ));
    }

    public function boot(): void
    {
        // Wire the referential-integrity delete guard: deleting an entity that
        // is still referenced as a relationship endpoint must fail loudly, not
        // silently orphan edge rows. (Historically this listener existed but
        // was never registered.)
        //
        // The kernel-services bus serves the dispatcher ONLY under the
        // Symfony-contracts FQCN (ProviderRegistryKernelServices::get());
        // resolving the foundation FQCN returns null and would silently skip
        // registration. Resolve the served key, then type-check against the
        // foundation contract (pattern per AuditServiceProvider::boot()).
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            new RelationshipDeleteGuardListener($entityTypeManager),
        );
        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new RelationshipPreSaveListener(new RelationshipValidator($entityTypeManager)),
        );
    }
}
