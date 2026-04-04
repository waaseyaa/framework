<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface DomainRouterInterface
{
    /**
     * Whether this router can handle the given request.
     *
     * The primary discriminator is the `_controller` request attribute,
     * but routers may also inspect `_route_object`, HTTP method, or
     * other request properties.
     */
    public function supports(Request $request): bool;

    /**
     * Handle the request and return a response.
     *
     * The request is fully populated with context attributes:
     * `_account`, `_broadcast_storage`, `_parsed_body`, `_waaseyaa_context`.
     */
    public function handle(Request $request): Response;
}
