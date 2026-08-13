<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class PathServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PathAlias's type metadata (id, label, keys, fields) lives on the
        // PathAlias class via #[ContentEntityType], #[ContentEntityKeys],
        // and #[Field] attributes.
        $this->entityType(EntityType::fromClass(
            PathAlias::class,
            group: 'structure',
        ));
    }

    public function boot(): void
    {
        // Resolve the Symfony-contracts dispatcher FQCN (the key the kernel-services
        // bus actually serves), then instanceof-check the foundation interface —
        // per RelationshipServiceProvider::boot()/AuditServiceProvider::boot().
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof \Waaseyaa\Foundation\Event\EventDispatcherInterface) {
            return;
        }
        $entityTypeManager = $this->resolveOptional(EntityTypeManagerInterface::class);
        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }
        $dispatcher->addListener(
            \Waaseyaa\EntityStorage\Event\BeforeSaveEvent::class,
            new PathAliasUniquenessListener($entityTypeManager),
        );
    }
}
