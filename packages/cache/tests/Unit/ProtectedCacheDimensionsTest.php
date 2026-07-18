<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\ProtectedCacheDimensions;

final class ProtectedCacheDimensionsTest extends TestCase
{
    #[Test]
    public function cacheIdentityChangesForEveryAuthorityDimension(): void
    {
        $base = new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'member', 'en', 11);
        $variants = [
            new ProtectedCacheDimensions('8', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-2', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-b', 'community-a', 'class-1', 'policy-1', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-b', 'class-1', 'policy-1', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-2', 'policy-1', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-2', 'member', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'admin', 'en', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'member', 'fr', 11),
            new ProtectedCacheDimensions('7', 'claims-1', 'tenant-a', 'community-a', 'class-1', 'policy-1', 'member', 'en', 12),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base->keySuffix(), $variant->keySuffix());
        }
    }
}
