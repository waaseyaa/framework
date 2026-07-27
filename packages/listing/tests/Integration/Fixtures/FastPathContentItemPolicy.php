<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Integration\Fixtures;

use Waaseyaa\Access\Gate\PolicyAttribute;

/**
 * Allow-everything convention policy for `content_item`, opted into the
 * FR-032 listing access fast path so pagination-push behaviour is reachable
 * in the bundle-field query-planning suite.
 */
#[PolicyAttribute(entityType: 'content_item')]
final class FastPathContentItemPolicy
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
