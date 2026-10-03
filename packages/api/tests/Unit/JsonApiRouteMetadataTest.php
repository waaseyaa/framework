<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;

#[CoversClass(JsonApiRouteProvider::class)]
final class JsonApiRouteMetadataTest extends TestCase
{
    public function testNumericLookingIdsUseTotalStringOrdering(): void
    {
        $entities = [['id' => '1e0', 'api_exposed' => true], ['id' => '01', 'api_exposed' => true], ['id' => '2', 'api_exposed' => true], ['id' => '10', 'api_exposed' => true]];
        $first = iterator_to_array(JsonApiRouteProvider::routeDefinitions(new RouteContributionContext('fixture.api', 0, entities: $entities)));
        $second = iterator_to_array(JsonApiRouteProvider::routeDefinitions(new RouteContributionContext('fixture.api', 0, entities: array_reverse($entities))));
        self::assertSame(array_map(static fn($route) => $route->toArray(), $first), array_map(static fn($route) => $route->toArray(), $second));
        self::assertSame(['01', '10', '1e0', '2'], array_values(array_unique(array_filter(array_column(array_column($first, 'defaults'), '_entity_type')))));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testColdDeclarationsDoNotAutoloadExecutionClassesOrRetainLiveInputs(): void
    {
        class_exists(JsonApiRouteProvider::class);
        class_exists(\Waaseyaa\Foundation\Routing\Metadata\RouteDefinition::class);
        class_exists(\Waaseyaa\Foundation\Routing\Metadata\HandlerReference::class);
        $context = new RouteContributionContext('fixture.api', 0, entities: [['id' => 'private', 'api_exposed' => false]]);
        $calls = [];
        $trap = static function (string $class) use (&$calls): void {
            $calls[] = $class;
            throw new \LogicException('Inspection autoloaded ' . $class);
        };
        spl_autoload_register($trap, true, true);
        try {
            $definitions = iterator_to_array(JsonApiRouteProvider::routeDefinitions($context, '/jsonapi', ordinal: 17));
        } finally {
            spl_autoload_unregister($trap);
        }
        self::assertSame([], $calls);
        self::assertSame([17, 18, 19], array_column($definitions, 'ordinal'));
        self::assertSame('/jsonapi/private/{path}', $definitions[2]->path);
        self::assertFalse(class_exists(\Waaseyaa\Api\Controller\NotExposedController::class, false));
        self::assertFalse(class_exists(\Waaseyaa\Api\ApiDiscoveryController::class, false));
        self::assertCount(0, iterator_to_array(JsonApiRouteProvider::routeDefinitions($context, workflow: true)));
    }

    public function testPureDeclarationsPreserveHistoricalFieldsAndSourceIdentity(): void
    {
        $context = new RouteContributionContext('fixture.api', 2, entities: [['id' => 'hidden', 'api_exposed' => false], ['id' => 'article', 'api_exposed' => true]]);
        $definitions = iterator_to_array(JsonApiRouteProvider::routeDefinitions($context));
        self::assertCount(14, $definitions);
        self::assertSame(range(0, 13), array_column($definitions, 'ordinal'));
        self::assertSame(['fixture.api'], array_values(array_unique(array_column($definitions, 'sourceId'))));
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/jsonapi-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([false => 'base', true => 'workflow'] as $workflow => $section) {
            $routes = iterator_to_array(JsonApiRouteProvider::routeDefinitions($context, workflow: (bool) $workflow));
            foreach ($routes as $index => $route) {
                self::assertSame($fixture[$section][$index], [
                    'name' => $route->name, 'path' => $route->path, 'handler' => $route->handler->id,
                    'methods' => $route->methods, 'defaults' => $route->defaults, 'options' => $route->options,
                    'requirements' => $route->requirements, 'host' => $route->host,
                    'schemes' => $route->schemes, 'condition' => $route->condition,
                ]);
            }
            self::assertCount(count($fixture[$section]), $routes);
        }
    }

    public function testDuplicateAndMissingExposureInputsRefuse(): void
    {
        foreach ([[['id' => 'article']], [['id' => 'article', 'api_exposed' => true], ['id' => 'article', 'api_exposed' => false]]] as $entities) {
            try {
                iterator_to_array(JsonApiRouteProvider::routeDefinitions(new RouteContributionContext('fixture.api', 0, entities: $entities)));
                self::fail('Malformed projection must refuse.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
