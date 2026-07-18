<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Additive optimized companion; the stable account-scope contract is unchanged.
 *
 * @internal
 * @api Activation-ready compiled-read scope SPI.
 */
interface FastAccountFieldReadScopeInterface extends AccountFieldReadScopeInterface
{
    public function currentContext(): ?AccountFieldReadContext;

    public function isCurrentContext(AccountFieldReadContext $context): bool;

    public function runWithGenerations(
        AuthorizationPrincipalInterface $principal,
        string $classificationGeneration,
        string $policyGeneration,
        callable $callback,
    ): mixed;
}
