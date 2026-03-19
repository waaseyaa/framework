<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class InertiaServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /** @return list<HttpMiddlewareInterface> */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [new InertiaMiddleware(Inertia::getVersion())];
    }
}
