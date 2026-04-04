<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface DomainRouterInterface
{
    public function supports(Request $request): bool;

    public function handle(Request $request): Response;
}
