<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Routing\Metadata;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;

#[CoversNothing]
final class RouteMetadataValuesTest extends TestCase
{
    public function testValuesPreserveFieldsWithoutLoadingAHandlerClass(): void
    {
        $loads = [];
        $loader = static function (string $class) use (&$loads): void {
            $loads[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $handler = HandlerReference::fromString('class:App\\UnloadedController::show');
            $route = new RouteDefinition(
                name: 'app.report',
                path: '/report/{id}',
                handler: $handler,
                methods: ['get', 'POST'],
                host: '{tenant}.example.test',
                schemes: ['https'],
                condition: "context.getMethod() == 'GET'",
                requirements: ['id' => '\\d+'],
                defaults: ['literal' => '<info>literal</info>', 'nested' => ['null' => null, 'ids' => [1, 2]]],
                options: ['_public' => true, '_permission' => 'view reports'],
                priority: 4,
                sourceId: 'app',
                ordinal: 2,
            );
            $data = $route->toArray();
            self::assertSame('class:App\\UnloadedController::show', $data['handler']);
            self::assertSame(['GET', 'POST'], $data['methods']);
            self::assertSame('{tenant}.example.test', $data['host']);
            self::assertSame(['https'], $data['schemes']);
            self::assertSame("context.getMethod() == 'GET'", $data['condition']);
            self::assertSame(['id' => '\\d+'], $data['requirements']);
            self::assertSame('<info>literal</info>', $data['defaults']['literal']);
            self::assertSame(['_public' => true, '_permission' => 'view reports'], $data['options']);
            self::assertSame(4, $data['priority']);
            self::assertSame([], $loads);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testReferencedInputAndReturnedProjectionsCannotMutateValues(): void
    {
        $value = 'original';
        $input = ['nested' => ['value' => &$value]];
        $route = new RouteDefinition('app', '/app', HandlerReference::fromString('builtin:render.page'), defaults: $input, sourceId: 'app');
        $context = new RouteContributionContext('app', 0, configuration: $input, capabilities: ['auth' => true], entities: [['id' => 'report']]);
        $snapshot = new RouteSnapshot([$route], ['schema' => 1, 'cohort' => ['app'], 'framework' => 'test-code']);
        $identity = $snapshot->identity;
        $value = 'changed';
        $projection = $snapshot->toArray();
        $projection['routes'][0]['defaults']['nested']['value'] = 'mutated';
        self::assertSame('original', $route->defaults['nested']['value']);
        self::assertSame('original', $context->configuration['nested']['value']);
        self::assertSame('original', $snapshot->toArray()['routes'][0]['defaults']['nested']['value']);
        self::assertSame($identity, $snapshot->identity);
        try {
            $route->defaults['nested']['value'] = 'mutated';
            self::fail('Route fields must be immutable.');
        } catch (\Error) {
            self::assertSame('original', $route->defaults['nested']['value']);
        }
    }

    #[DataProvider('invalidMetadata')]
    public function testUnsafeMetadataIsRejectedWithoutSerializationSideEffects(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouteDefinition('app', '/app', HandlerReference::fromString('builtin:render.page'), defaults: ['nested' => [$value]], sourceId: 'app');
    }

    public static function invalidMetadata(): iterable
    {
        yield 'object' => [new \stdClass()];
        yield 'closure' => [static fn() => null];
        yield 'callable array' => [['App\\Controller', 'show']];
        yield 'global callable array' => [['DateTime', 'createFromFormat']];
        yield 'infinity' => [INF];
        yield 'nan' => [NAN];
        yield 'invalid utf8' => ["\xff"];
        yield 'recursive array' => [self::recursiveArray()];
    }

    private static function recursiveArray(): array
    {
        $value = [];
        $value['loop'] = &$value;
        return $value;
    }

    #[DataProvider('invalidHandlers')]
    public function testInvalidHandlerIdentitiesAreRefused(string $handler): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HandlerReference::fromString($handler);
    }

    public static function invalidHandlers(): iterable
    {
        foreach (['builtin:unknown', 'service:::show', 'service:app::show::extra', 'class:App/Controller::show', 'class:App\\Controller::9bad', 'render.page', 'service:../secret::show'] as $handler) {
            yield [$handler];
        }
    }

    public function testSnapshotIdentityIsDeterministicAndIncludesInputsAndDefinitions(): void
    {
        $route = new RouteDefinition('app', '/app', HandlerReference::fromString('service:app.report::show'), sourceId: 'app');
        $a = new RouteSnapshot([$route], ['cohort' => ['app'], 'schema' => 1]);
        $b = new RouteSnapshot([$route], ['schema' => 1, 'cohort' => ['app']]);
        self::assertSame($a->identity, $b->identity);
        self::assertNotSame($a->identity, new RouteSnapshot([$route], ['cohort' => ['other'], 'schema' => 1])->identity);
        $changed = new RouteDefinition('app', '/changed', $route->handler, sourceId: 'app');
        self::assertNotSame($a->identity, new RouteSnapshot([$changed], ['cohort' => ['app'], 'schema' => 1])->identity);
    }

    public function testDuplicateNamesCannotProduceASnapshot(): void
    {
        $route = new RouteDefinition('app', '/app', HandlerReference::fromString('builtin:render.page'), sourceId: 'app');
        $this->expectException(\InvalidArgumentException::class);
        new RouteSnapshot([$route, $route], ['cohort' => ['app']]);
    }

    public function testResourceAndSerializableObjectCannotEscapeAsMetadata(): void
    {
        $stream = fopen('php://memory', 'r+');
        try {
            foreach ([$stream, new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    throw new \LogicException('Serialization must not execute.');
                }
            }] as $value) {
                try {
                    new RouteContributionContext('app', 0, configuration: ['nested' => $value]);
                    self::fail('Execution values must not enter the contribution context.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public function testNumericMetadataTypesCannotShareAnIdentity(): void
    {
        $integer = new RouteDefinition('app', '/app', HandlerReference::fromString('builtin:render.page'), defaults: ['value' => 1], sourceId: 'app');
        $float = new RouteDefinition('app', '/app', $integer->handler, defaults: ['value' => 1.0], sourceId: 'app');
        self::assertNotSame(new RouteSnapshot([$integer], [])->identity, new RouteSnapshot([$float], [])->identity);
    }

    public function testMapAndListMetadataCannotShareAnIdentity(): void
    {
        $handler = HandlerReference::fromString('builtin:render.page');
        $list = new RouteDefinition('app', '/app', $handler, defaults: ['value' => ['a b', 'c d']], sourceId: 'app');
        // Use non-callable string syntax to isolate array-shape identity.
        $map = new RouteDefinition('app', '/app', $handler, defaults: ['value' => [1 => 'c d', 0 => 'a b']], sourceId: 'app');
        self::assertNotSame(new RouteSnapshot([$map], [])->identity, new RouteSnapshot([$list], [])->identity);
        self::assertSame([1 => 'c d', 0 => 'a b'], $map->defaults['value']);
    }

    public function testCallableShapeIsReservedButOrdinaryStringListsRemainData(): void
    {
        $context = new RouteContributionContext('app', 0, configuration: ['labels' => ['view report', 'edit report']]);
        self::assertSame(['view report', 'edit report'], $context->configuration['labels']);
        $this->expectException(\InvalidArgumentException::class);
        new RouteContributionContext('app', 0, configuration: ['labels' => ['UnloadedClass', 'method']]);
    }

    #[DataProvider('invalidRouteFields')]
    public function testMalformedRouteFieldsAreRefused(array $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouteDefinition(...array_replace(['name' => 'app', 'path' => '/app', 'handler' => HandlerReference::fromString('builtin:render.page'), 'sourceId' => 'app'], $fields));
    }

    public static function invalidRouteFields(): iterable
    {
        foreach ([['methods' => ['GE T']], ['methods' => ['key' => 'GET']], ['schemes' => ['ftp']], ['sourceId' => ''], ['ordinal' => -1], ['defaults' => ['_controller' => 'hidden']], ['options' => ['_waaseyaa_priority' => 1]], ['requirements' => ['id' => 42]]] as $fields) {
            yield [$fields];
        }
    }

    #[DataProvider('invalidContextFields')]
    public function testMalformedContributionInputsAreRefused(array $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouteContributionContext(...array_replace(['sourceId' => 'app', 'sourceOrder' => 0], $fields));
    }

    public static function invalidContextFields(): iterable
    {
        foreach ([['capabilities' => ['auth' => 1]], ['capabilities' => ['' => true]], ['entities' => [['name' => 'missing-id']]], ['entities' => ['map' => ['id' => 'app']]], ['sourceOrder' => -1]] as $fields) {
            yield [$fields];
        }
    }

    public function testClosedSchemeListAllowsBothHttpAndHttps(): void
    {
        $route = new RouteDefinition('app', '/app', HandlerReference::fromString('builtin:render.page'), schemes: ['http', 'https'], sourceId: 'app');
        self::assertSame(['http', 'https'], $route->schemes);
    }

    public function testNumericStringMapKeysHaveDeterministicOrdering(): void
    {
        $a = new RouteSnapshot([], ['01' => 'one', '+1' => 'plus']);
        $b = new RouteSnapshot([], ['+1' => 'plus', '01' => 'one']);
        self::assertSame($a->identity, $b->identity);
    }
}
