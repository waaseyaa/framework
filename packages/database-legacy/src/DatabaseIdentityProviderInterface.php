<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/** Supplies a stable, non-secret identity for one physical database authority. @api */
interface DatabaseIdentityProviderInterface
{
    public function databaseIdentity(): string;
}
