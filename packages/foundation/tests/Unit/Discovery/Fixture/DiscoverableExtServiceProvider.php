<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery\Fixture;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * A provider the Composer dev autoloader can actually resolve, so a fixture
 * package that declares it is a genuine cache hit on reload (#2829). A
 * declared-but-undiscoverable provider is a different contract: it forces a
 * recompile to stamp the known-missing roster.
 */
final class DiscoverableExtServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
