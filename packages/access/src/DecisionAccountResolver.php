<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/** Resolves an access-decision identity without ever accepting a live entity account. @api */
final class DecisionAccountResolver
{
    public static function resolve(mixed $principal, mixed $account): ?AuthorizationPrincipalInterface
    {
        if ($principal instanceof AuthorizationPrincipalInterface) {
            if ($account instanceof AccountInterface
                && (string) $principal->id() !== (string) $account->id()
            ) {
                return null;
            }

            return $principal;
        }

        // Backward-compatible immutable/system account value objects remain
        // valid. Entity-backed accounts must cross the audited principal
        // factory first; they can never reach an access policy directly.
        return $account instanceof AuthorizationPrincipalInterface && !$account instanceof EntityInterface
            ? $account
            : null;
    }
}
