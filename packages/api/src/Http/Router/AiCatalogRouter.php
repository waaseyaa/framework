<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\AiCatalogController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

final readonly class AiCatalogRouter implements DomainRouterInterface
{
    public function __construct(private AiCatalogController $controller) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === 'ai.catalog';
    }

    public function handle(Request $request): Response
    {
        return $this->controller->serve($request);
    }
}
