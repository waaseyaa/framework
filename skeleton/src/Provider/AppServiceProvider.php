<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /**
     * The homepage is deliberately absent here. The framework's `public.home`
     * route already binds `/` to the SSR render pipeline, which resolves
     * `home.html.twig` through the real Twig environment — theme chain,
     * language negotiation, cache headers and SSR error pages included.
     * Edit `templates/home.html.twig` to change the homepage; the application
     * template outranks the framework's copy in the theme chain.
     *
     * Add application routes here with `->priority(1)` or higher when they must
     * outrank the SSR `/{path}` catch-all (see docs/specs/api-layer.md).
     */
    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
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
