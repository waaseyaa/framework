<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Bootstrap boundary that snapshots an account into immutable claims.
 * Entity-backed account extraction is implemented by a closed primitive in WP2.
 *
 * @api
 */
interface AccountPrincipalFactoryInterface
{
    public function fromAccount(AccountInterface $account): AuthorizationPrincipalInterface;
}
