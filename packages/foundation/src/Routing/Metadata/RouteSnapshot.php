<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

use Waaseyaa\Foundation\Schema\Diff\CanonicalJson;

/** Completed immutable route data; lifecycle admission belongs to the composer. @api */
final readonly class RouteSnapshot
{
    /** @var list<RouteDefinition> */
    public array $routes;
    public array $inputs;
    public string $identity;

    /** @param array<array-key, mixed> $routes Validated into an immutable declaration list. */
    public function __construct(array $routes, array $inputs)
    {
        if (!array_is_list($routes)) {
            throw new \InvalidArgumentException('Route snapshot declarations must be a list.');
        }
        $names = [];
        $copy = [];
        foreach ($routes as $route) {
            if (!$route instanceof RouteDefinition || isset($names[$route->name])) {
                throw new \InvalidArgumentException('Invalid or duplicate route declaration.');
            }
            $names[$route->name] = true;
            $copy[] = $route;
        }
        $this->routes = $copy;
        $this->inputs = ScalarRouteMetadata::copy($inputs);
        // Tag array shape before ordering maps: canonical key sorting alone
        // can turn a numeric-key map into a list. Preserve finite float types.
        $encoded = json_encode(CanonicalJson::canonicalize(ScalarRouteMetadata::identityValue($this->toArray())), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->identity = hash('sha256', $encoded);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['schema' => 1, 'inputs' => $this->inputs, 'routes' => array_map(static fn(RouteDefinition $route): array => $route->toArray(), $this->routes)];
    }
}
