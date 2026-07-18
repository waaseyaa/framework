<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @api */
interface AccountFieldReadScopeInterface
{
    public function current(): ?AuthorizationPrincipalInterface;

    public function run(AuthorizationPrincipalInterface $principal, callable $callback): mixed;
}
