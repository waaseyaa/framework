<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\ApiCatalogController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

final readonly class ApiCatalogRouter implements DomainRouterInterface
{
    public function __construct(private ApiCatalogController $controller) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === 'api.catalog';
    }

    public function handle(Request $request): Response
    {
        return $this->controller->serve($request);
    }
}
