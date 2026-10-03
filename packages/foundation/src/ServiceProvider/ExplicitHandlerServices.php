<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ServiceLocatorTrait;

/** Explicit execution factories and their current request; never a snapshot input. @api */
final class ExplicitHandlerServices implements ContainerInterface
{
    use ServiceLocatorTrait {
        __construct as private initializeFactories;
    }

    /** @param array<string, callable> $factories Explicit declarations; their owners retain lifetime policy. */
    public function __construct(private readonly Request $request, array $factories)
    {
        $this->initializeFactories($factories);
    }

    public function request(): Request
    {
        return $this->request;
    }
}
