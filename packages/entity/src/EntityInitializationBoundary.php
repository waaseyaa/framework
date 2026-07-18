<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Composition-root holder for one atomic entity initialization identity. @api */
final class EntityInitializationBoundary
{
    private readonly EntityInitializationIdentity $identity;

    public function __construct()
    {
        $this->identity = new EntityInitializationIdentity();
    }

    public function factory(): EntityInitializationFactory
    {
        return new EntityInitializationFactory($this->identity);
    }

    public function installer(): EntityInitializationInstaller
    {
        return new EntityInitializationInstaller($this->identity);
    }
}
