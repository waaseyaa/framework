<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Repository/compiler role that seals raw input before entity code can observe it. @api */
final readonly class EntityInitializationFactory
{
    /** @internal Issued by EntityInitializationBoundary. */
    public function __construct(private EntityInitializationIdentity $identity) {}

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     */
    public function seal(
        array $values,
        EntityReadLayout $layout,
        EntityStructure $structure,
        string $entityTypeId,
        array $entityKeys,
        ?EntityValueReadGuardInterface $guard = null,
    ): EntityInitialization {
        return EntityInitialization::forBoundary(
            EntityValueContainer::seal($values, $layout, $guard),
            $structure,
            $entityTypeId,
            $entityKeys,
            $this->identity,
        );
    }
}
