<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Opaque, non-iterable, non-serializable atomically sealed entity initialization. @api */
final class EntityInitialization
{
    /** @param array<string, string> $entityKeys */
    private function __construct(
        private readonly EntityValueContainer $container,
        private readonly EntityStructure $structure,
        private readonly string $entityTypeId,
        private readonly array $entityKeys,
        private readonly EntityInitializationIdentity $identity,
    ) {}

    /** @internal EntityInitializationFactory only. @param array<string, string> $entityKeys */
    public static function forBoundary(
        EntityValueContainer $container,
        EntityStructure $structure,
        string $entityTypeId,
        array $entityKeys,
        EntityInitializationIdentity $identity,
    ): self {
        return new self($container, $structure, $entityTypeId, $entityKeys, $identity);
    }

    /** @internal EntityInitializationInstaller only. */
    public function install(EntityBase $entity, EntityInitializationIdentity $identity): void
    {
        if ($identity !== $this->identity) {
            throw new \LogicException('The installer for this entity initialization boundary is required.');
        }
        $entity->_installSealedInitialization(
            $this->container,
            $this->structure,
            $this->entityTypeId,
            $this->entityKeys,
            $identity,
            $this->identity,
        );
    }

    public function __serialize(): array
    {
        throw new \LogicException('Entity initializations cannot be serialized.');
    }
}
