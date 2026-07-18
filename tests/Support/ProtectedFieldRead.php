<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityReadRuntime;

/** Test-only scoped installation of the production protected-read guard. */
final class ProtectedFieldRead
{
    /** @param list<\Waaseyaa\Access\AccessPolicyInterface> $policies */
    public static function run(array $policies, AuthorizationPrincipalInterface $principal, callable $callback): mixed
    {
        $previous = EntityReadRuntime::guard();
        $scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler($policies);
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...)));
        try {
            return $scope->run($principal, $callback);
        } finally {
            EntityReadRuntime::installGuard($previous);
        }
    }
}
