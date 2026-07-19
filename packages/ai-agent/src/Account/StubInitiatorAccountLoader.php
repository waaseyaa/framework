<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Account;

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Default {@see InitiatorAccountLoaderInterface} implementation: returns
 * a minimal authenticated account carrying just the id.
 *
 * Has no permissions and no roles beyond `authenticated`. The executor's
 * tool-call access checks still flow through `AgentTool::execute()`, so
 * apps that require permission semantics MUST wire a real loader.
 *
 * @api
 */
final class StubInitiatorAccountLoader implements InitiatorAccountLoaderInterface
{
    public function load(int|string $accountId): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal($accountId, true, ['authenticated'], [], 'stub-initiator');
    }
}
