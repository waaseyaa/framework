<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Entity-layer role that atomically installs a boundary-matched sealed payload. @api */
final readonly class EntityInitializationInstaller
{
    /** @internal Issued by EntityInitializationBoundary. */
    public function __construct(private EntityInitializationIdentity $identity) {}

    public function install(EntityBase $entity, EntityInitialization $initialization): void
    {
        $initialization->install($entity, $this->identity);
    }

    /** @param class-string<EntityBase> $class */
    public function instantiate(string $class, EntityInitialization $initialization): EntityBase
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isSubclassOf(EntityBase::class) && $class !== EntityBase::class) {
            throw new \InvalidArgumentException(sprintf('Entity initialization requires an %s subclass.', EntityBase::class));
        }
        /** @var EntityBase $entity */
        $entity = $reflection->newInstanceWithoutConstructor();
        $this->install($entity, $initialization);

        return $entity;
    }
}
