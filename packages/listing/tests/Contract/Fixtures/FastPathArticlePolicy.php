<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract\Fixtures;

use Waaseyaa\Access\Gate\ListingFastPathProbeInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Allow-all `article` policy that OPTS INTO the listing access fast-path by
 * declaring {@see ListingFastPathProbeInterface::FAST_PATH_CONST} = true.
 *
 * With this policy bound, the resolver's `canUseAccessFastPath()` returns true
 * for the default `{'view'}` access ops, so a paged, exact-total listing pushes
 * pagination down to the driver (SQL `count()` + bounded `findBy()`) instead of
 * hydrating every row. Mirrors a production policy that has reviewed its row
 * access as expressible purely through the listing's filters.
 */
#[PolicyAttribute(entityType: 'article')]
final class FastPathArticlePolicy
{
    public const bool SUPPORTS_LISTING_FAST_PATH = true;

    public function view(?object $user, mixed $subject): bool
    {
        return true;
    }

    public function update(?object $user, mixed $subject): bool
    {
        return true;
    }

    public function delete(?object $user, mixed $subject): bool
    {
        return true;
    }

    public function translate(?object $user, mixed $subject): bool
    {
        return true;
    }
}
