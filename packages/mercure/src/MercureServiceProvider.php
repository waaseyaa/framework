<?php

declare(strict_types=1);

namespace Waaseyaa\Mercure;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MercureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(MercurePublisher::class, fn () => new MercurePublisher(
            hubUrl: $this->config['mercure']['hub_url'] ?? '',
            jwtSecret: $this->config['mercure']['jwt_secret'] ?? '',
        ));
    }
}
