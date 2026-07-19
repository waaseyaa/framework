<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Symfony\Component\Routing\Exception\MethodNotAllowedException as SymfonyMethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException as SymfonyResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Routing\Exception\RouteMethodNotAllowedException;
use Waaseyaa\Routing\Exception\RouteNotFoundException;

/**
 * Waaseyaa router wrapping Symfony's routing components.
 *
 * Provides route matching (URL -> parameters) and URL generation
 * (route name + parameters -> URL).
 * @api
 */
final class WaaseyaaRouter
{
    private RouteCollection $routes;
    private ?UrlMatcher $matcher;
    private ?UrlGenerator $generator;
    private RequestContext $context;

    public function __construct(?RequestContext $context = null)
    {
        $this->routes = new RouteCollection();
        $this->context = $context ?? new RequestContext();
        $this->matcher = null;
        $this->generator = null;
    }

    /**
     * Add a named route to the collection.
     *
     * Invalidates any cached matcher/generator so they are rebuilt on next use.
     */
    public function addRoute(string $name, Route $route): void
    {
        if ($this->routes->get($name) !== null) {
            throw new \LogicException(sprintf('Duplicate route name registered: %s', $name));
        }
        $this->routes->add($name, $route);
        // Reset matchers/generators when routes change.
        $this->matcher = null;
        $this->generator = null;
    }

    /**
     * Remove a named route if present (used when an app intentionally overrides a framework route).
     */
    public function removeRoute(string $name): void
    {
        $this->routes->remove($name);
        $this->matcher = null;
        $this->generator = null;
    }

    /**
     * Re-order routes by {@see RouteBuilder::priority()} (higher priority wins; default 0).
     */
    public function sortRoutesByPriority(): void
    {
        $named = $this->routes->all();
        /** @var array<int, list<array{name: string, route: Route}>> $buckets */
        $buckets = [];
        foreach ($named as $name => $route) {
            $priority = (int) ($route->getOption('_waaseyaa_priority') ?? 0);
            $buckets[$priority][] = ['name' => $name, 'route' => $route];
        }
        krsort($buckets, SORT_NUMERIC);
        $this->routes = new RouteCollection();
        foreach ($buckets as $routes) {
            foreach ($routes as $route) {
                $this->routes->add($route['name'], $route['route']);
            }
        }
        $this->matcher = null;
        $this->generator = null;
    }

    /**
     * Match a path to a route and return the parameters.
     *
     * The returned array always includes a '_route' key with the matched route name.
     *
     * @param string $pathinfo The path to match (e.g. "/node/42").
     * @return array<string, mixed> The matched parameters.
     *
     * @throws RouteNotFoundException
     * @throws RouteMethodNotAllowedException
     */
    public function match(string $pathinfo): array
    {
        if ($this->matcher === null) {
            $this->matcher = new UrlMatcher($this->routes, $this->context);
        }

        try {
            return $this->matcher->match($pathinfo);
        } catch (SymfonyResourceNotFoundException $e) {
            throw new RouteNotFoundException($pathinfo, $e);
        } catch (SymfonyMethodNotAllowedException $e) {
            throw new RouteMethodNotAllowedException(
                $e->getAllowedMethods(),
                $e->getMessage(),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name The route name.
     * @param array<string, mixed> $parameters Parameters to substitute into the route pattern.
     * @return string The generated URL.
     *
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException
     * @throws \Symfony\Component\Routing\Exception\MissingMandatoryParametersException
     * @throws \Symfony\Component\Routing\Exception\InvalidParameterException
     */
    public function generate(string $name, array $parameters = []): string
    {
        if ($this->generator === null) {
            $this->generator = new UrlGenerator($this->routes, $this->context);
        }

        return $this->generator->generate($name, $parameters);
    }

    /**
     * Return the underlying route collection.
     */
    public function getRouteCollection(): RouteCollection
    {
        return clone $this->routes;
    }
}
