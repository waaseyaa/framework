<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\ServiceProvider\ExplicitHandlerServices;
use Waaseyaa\Routing\Exception\HandlerResolutionException;

/** Resolves a verified matched declaration only, without invoking its target. @api */
final class RouteHandlerResolver
{
    /** @var array<string, HandlerReference> */
    private array $handlers = [];

    public function __construct(RouteSnapshot $snapshot, private readonly ExplicitHandlerServices $services)
    {
        foreach ($snapshot->routes as $definition) {
            $this->handlers[$definition->name] = $definition->handler;
        }
    }

    public function resolveMatched(): \Closure|string
    {
        $request = $this->services->request();
        $name = $request->attributes->get('_route');
        if (!is_string($name) || !isset($this->handlers[$name])) {
            throw new HandlerResolutionException('<unmatched>', '<unavailable>', 'unmatched');
        }
        $handler = $this->handlers[$name];
        if ($request->attributes->get('_controller') !== $handler->id) {
            throw new HandlerResolutionException($name, $handler->id, 'handler-mismatch');
        }
        if ($handler->kind === 'builtin') {
            return $handler->target;
        }
        try {
            $instance = $this->services->get($handler->target);
        } catch (\Throwable) {
            throw new HandlerResolutionException($name, $handler->id, 'resolution-failed');
        }
        $method = $handler->method;
        if (!is_object($instance) || $method === null || !method_exists($instance, $method) || !new \ReflectionMethod($instance, $method)->isPublic() || !is_callable([$instance, $method])) {
            throw new HandlerResolutionException($name, $handler->id, 'invalid-method');
        }
        return \Closure::fromCallable([$instance, $method]);
    }
}
