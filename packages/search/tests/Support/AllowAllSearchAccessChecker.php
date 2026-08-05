<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Support;

use Waaseyaa\Search\Access\SearchAccessChecker;

/** Test-only explicit authority for FTS behavior tests unrelated to access. */
final class AllowAllSearchAccessChecker implements SearchAccessChecker
{
    public function canView(string $documentId, string $entityType): bool
    {
        return true;
    }
}
