<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

interface ServiceProviderInterface
{
    public function register(): void;
    public function boot(): void;
    public function provides(): array;
    public function isDeferred(): bool;
}
