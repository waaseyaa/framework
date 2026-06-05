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
 * @api
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
     * Symfony `Route` defaults may use `[FQCN, method]`; this builder normalizes
     * that shape to the `FQCN::method` string domain routers expect.
     *
     * @param string|callable|array{0: string, 1: string} $controller
     */
    public function controller(string|callable|array $controller): self
    {
        $this->defaults['_controller'] = self::normalizeControllerDefault($controller);

        return $this;
    }

    /**
     * Coerce Symfony-style `[FQCN, method]` into the `FQCN::method` string.
     * Pass-through for strings, callables, and other attribute values.
     */
    public static function normalizeControllerDefault(mixed $controller): mixed
    {
        if (
            is_array($controller)
            && count($controller) === 2
            && is_string($controller[0] ?? null)
            && is_string($controller[1] ?? null)
        ) {
            return $controller[0] . '::' . $controller[1];
        }

        return $controller;
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
     * Bind a route parameter name to an expected PHP entity class (validated after load).
     *
     * @param class-string $class
     */
    public function bind(string $name, string $class): self
    {
        if (!isset($this->options[RouteFingerprint::BINDINGS_OPTION])) {
            $this->options[RouteFingerprint::BINDINGS_OPTION] = [];
        }
        /** @var array<string, class-string> $bindings */
        $bindings = $this->options[RouteFingerprint::BINDINGS_OPTION];
        $bindings[$name] = $class;
        $this->options[RouteFingerprint::BINDINGS_OPTION] = $bindings;

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
     * Require an active PHP session (without requiring a Waaseyaa user account).
     *
     * Use for routes where guests/anonymous users need session state
     * (e.g., chat nicknames, wizard progress) but don't need authentication.
     *
     * @param list<string> $requiredKeys Session keys that must be present.
     */
    public function requireSession(array $requiredKeys = []): self
    {
        $this->options['_session'] = $requiredKeys === [] ? true : $requiredKeys;
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
     * Route ordering hint for {@see WaaseyaaRouter::sortRoutesByPriority()} (higher matches first).
     */
    public function priority(int $priority): self
    {
        $this->options['_waaseyaa_priority'] = $priority;

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
