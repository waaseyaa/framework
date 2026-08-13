<?php

declare(strict_types=1);

namespace App\Provider;

use App\Controller\HomeController;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(HomeController::class, static fn (): HomeController => new HomeController());
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $controller = $this->resolve(HomeController::class);

        $router->addRoute(
            'home',
            RouteBuilder::create('/')
                ->controller(fn () => $controller->index())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'waaseyaa.clean_url_probe',
            RouteBuilder::create(CleanUrlProbe::PATH)
                ->controller(static fn() => new Response(
                    CleanUrlProbe::SENTINEL,
                    200,
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }
}
