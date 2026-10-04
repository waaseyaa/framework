<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Introspection\Routing;

use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

final class RoutingIntrospectionProvider implements GraphSectionProviderInterface
{
    private const array ACCESS_OPTION_KEYS = [
        '_public' => 'public',
        '_authenticated' => 'authenticated',
        '_permission' => 'permission',
        '_role' => 'role',
        '_gate' => 'gate',
        '_session' => 'session',
    ];

    public function __construct(
        /** @var RouteCollection|\Closure(): RouteCollection */
        private readonly RouteCollection|\Closure $routes,
    ) {}

    public function getKey(): string
    {
        return 'routing';
    }

    public function provide(): GraphSection
    {
        $data = [];

        $routes = $this->routes instanceof \Closure ? ($this->routes)() : $this->routes;
        foreach ($routes->all() as $name => $route) {
            $defaults = $route->getDefaults();
            $options = $route->getOptions();

            $access = [];

            foreach (self::ACCESS_OPTION_KEYS as $optionKey => $accessKey) {
                if (\array_key_exists($optionKey, $options)) {
                    $access[$accessKey] = $options[$optionKey];
                }
            }

            $data[$name] = [
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'controller' => $defaults['_controller'] ?? null,
                'access' => $access,
            ];
        }

        return new GraphSection(
            key: 'routing',
            version: '1.0',
            data: $data,
        );
    }
}
