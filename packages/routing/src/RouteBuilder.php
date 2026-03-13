<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Symfony\Component\Routing\Route;

/**
 * Fluent API for building Symfony Route objects with Waaseyaa conventions.
 *
 * Usage:
 *   $route = RouteBuilder::create('/node/{node}')
 *       ->controller('App\Controller\NodeController::view')
 *       ->entityParameter('node', 'node')
 *       ->requirePermission('access content')
 *       ->methods('GET')
 *       ->build();
 */
final class RouteBuilder
{
    private string $path;

    /** @var array<string, mixed> */
    private array $defaults = [];

    /** @var array<string, string> */
    private array $requirements = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var string[] */
    private array $methods = [];

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Begin building a route for the given path.
     */
    public static function create(string $path): self
    {
        return new self($path);
    }

    /**
     * Set the controller for this route.
     *
     * @param string|callable $controller The controller reference.
     */
    public function controller(string|callable $controller): self
    {
        $this->defaults['_controller'] = $controller;
        return $this;
    }

    /**
     * Set the allowed HTTP methods.
     */
    public function methods(string ...$methods): self
    {
        $this->methods = $methods;
        return $this;
    }

    /**
     * Declare that a route parameter should be upcasted to an entity.
     *
     * @param string $name The parameter name (e.g. 'node').
     * @param string $entityType The entity type ID (e.g. 'node').
     */
    public function entityParameter(string $name, string $entityType): self
    {
        if (!isset($this->options['parameters'])) {
            $this->options['parameters'] = [];
        }
        $this->options['parameters'][$name] = ['type' => "entity:{$entityType}"];
        return $this;
    }

    /**
     * Require that the user has a specific permission.
     */
    public function requirePermission(string $permission): self
    {
        $this->options['_permission'] = $permission;
        return $this;
    }

    /**
     * Require that the user has a specific role.
     */
    public function requireRole(string $role): self
    {
        $this->options['_role'] = $role;
        return $this;
    }

    /**
     * Require that the request is from an authenticated (non-anonymous) account.
     */
    public function requireAuthentication(): self
    {
        $this->options['_authenticated'] = true;
        return $this;
    }

    /**
     * Allow all users (marks route as public).
     */
    public function allowAll(): self
    {
        $this->options['_public'] = true;
        return $this;
    }

    /**
     * Mark route as SSR render route.
     */
    public function render(bool $enabled = true): self
    {
        $this->options['_render'] = $enabled;
        return $this;
    }

    /**
     * Exempt this route from CSRF token validation.
     *
     * Use for routes that have their own authentication model (e.g., MCP, API keys).
     */
    public function csrfExempt(): self
    {
        $this->options['_csrf'] = false;
        return $this;
    }

    /**
     * Mark route as a JSON:API route (enables JSON body parsing on POST/PATCH).
     */
    public function jsonApi(): self
    {
        $this->options['_json_api'] = true;
        return $this;
    }

    /**
     * Add a regex requirement for a route parameter.
     */
    public function requirement(string $key, string $regex): self
    {
        $this->requirements[$key] = $regex;
        return $this;
    }

    /**
     * Set a default value for a route parameter.
     */
    public function default(string $key, mixed $value): self
    {
        $this->defaults[$key] = $value;
        return $this;
    }

    /**
     * Build and return the configured Symfony Route.
     */
    public function build(): Route
    {
        $route = new Route(
            $this->path,
            $this->defaults,
            $this->requirements,
            $this->options,
        );

        if (!empty($this->methods)) {
            $route->setMethods($this->methods);
        }

        return $route;
    }
}
