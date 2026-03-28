<?php

declare(strict_types=1);

namespace Waaseyaa\Geo;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings yet — package provides static utilities.
    }
}
