<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Introspection\JsonApi;

use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

final class JsonApiIntrospectionProvider implements GraphSectionProviderInterface
{
    public function __construct(
        /** @var RouteCollection|\Closure(): RouteCollection */
        private readonly RouteCollection|\Closure $routes,
    ) {}

    public function getKey(): string
    {
        return 'jsonapi';
    }

    public function provide(): GraphSection
    {
        $data = [];

        $routes = $this->routes instanceof \Closure ? ($this->routes)() : $this->routes;
        foreach ($routes as $name => $route) {
            $options = $route->getOptions();

            if (!isset($options['_json_api']) || $options['_json_api'] === false) {
                continue;
            }

            $entityType = $route->getDefault('_entity_type');
            if (!is_string($entityType)) {
                $entityType = $this->resolveEntityType($options);
            }

            $data[$name] = [
                'entity_type' => $entityType,
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'controller' => $route->getDefault('_controller'),
            ];
        }

        return new GraphSection(
            key: 'jsonapi',
            version: '1.0',
            data: $data,
        );
    }

    private function resolveEntityType(array $options): ?string
    {
        $parameters = $options['parameters'] ?? [];

        foreach ($parameters as $paramConfig) {
            $type = $paramConfig['type'] ?? null;

            if (\is_string($type) && str_starts_with($type, 'entity:')) {
                return substr($type, 7);
            }
        }

        return null;
    }
}
