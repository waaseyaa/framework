<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Routing\Exception\RouteMethodNotAllowedException;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;

final class RouteMetadataCompilerTest extends TestCase
{
    public function testAllFieldsSurviveAndHandlersAreNeverLoaded(): void
    {
        $definition = new RouteDefinition(
            'article.show',
            '/articles/{id}',
            HandlerReference::fromString('class:NeverLoad\\ArticleController::show'),
            methods: ['get'],
            host: '{tenant}.example.test',
            schemes: ['https'],
            condition: 'context.getMethod() == "GET"',
            requirements: ['id' => '\\d+', 'tenant' => '[a-z]+'],
            defaults: ['format' => 'json', 'nested' => ['flag' => true]],
            options: ['_authenticated' => true, '_public' => true, '_permission' => 'view content', 'mapping' => ['id' => 'article']],
            priority: 12,
            sourceId: 'fixture',
        );
        $snapshot = new RouteSnapshot([$definition], []);
        $autoloads = [];
        $trap = static function (string $class) use (&$autoloads): void {
            if (str_starts_with($class, 'NeverLoad\\')) {
                $autoloads[] = $class;
                throw new \LogicException('Handler loading is prohibited.');
            }
        };
        spl_autoload_register($trap, true, true);
        try {
            $compiled = new RouteMetadataCompiler()->compile($snapshot)->get('article.show');
        } finally {
            spl_autoload_unregister($trap);
        }
        self::assertSame([], $autoloads);
        self::assertSame('/articles/{id}', $compiled->getPath());
        self::assertSame(['GET'], $compiled->getMethods());
        self::assertSame('{tenant}.example.test', $compiled->getHost());
        self::assertSame(['https'], $compiled->getSchemes());
        self::assertSame($definition->condition, $compiled->getCondition());
        self::assertSame($definition->requirements, $compiled->getRequirements());
        self::assertSame($definition->defaults + ['_controller' => $definition->handler->id], $compiled->getDefaults());
        self::assertSame(['compiler_class' => \Symfony\Component\Routing\RouteCompiler::class] + $definition->options + ['_waaseyaa_priority' => 12], $compiled->getOptions());
    }

    public function testSymfonyMatchesAndGeneratesUsingRequestLocalContext(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition(
            'article',
            '/articles/{id}',
            HandlerReference::fromString('service:article.controller::show'),
            methods: ['GET'],
            host: '{tenant}.example.test',
            schemes: ['https'],
            requirements: ['id' => '\\d+'],
            defaults: ['format' => 'json'],
            sourceId: 'fixture',
        )], []);
        $context = new RequestContext('', 'GET', 'alpha.example.test', 'https');
        $router = new WaaseyaaRouter($context, $snapshot);
        $match = $router->match('/articles/42');
        self::assertSame('42', $match['id']);
        self::assertSame('alpha', $match['tenant']);
        self::assertSame('json', $match['format']);
        self::assertSame('service:article.controller::show', $match['_controller']);
        self::assertSame('/articles/7', $router->generate('article', ['id' => 7, 'tenant' => 'alpha']));
        $other = new WaaseyaaRouter(new RequestContext('', 'POST', 'alpha.example.test', 'https'), $snapshot);
        try {
            $other->match('/articles/42');
            self::fail('Wrong method must refuse.');
        } catch (RouteMethodNotAllowedException) {
            self::assertSame('article', $router->match('/articles/42')['_route']);
        }
        $this->expectException(RouteNotFoundException::class);
        $router->match('/articles/not-a-number');
    }

    public function testWrongHostAndSchemeRefuse(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('secure', '/secure', HandlerReference::fromString('builtin:openapi'), host: 'example.test', schemes: ['https'], sourceId: 'fixture')], []);
        foreach ([new RequestContext('', 'GET', 'wrong.test', 'https'), new RequestContext('', 'GET', 'example.test', 'http')] as $context) {
            try {
                new WaaseyaaRouter($context, $snapshot)->match('/secure');
                self::fail('Host and scheme restrictions must survive.');
            } catch (RouteNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testConditionsAreNotEvaluatedUntilMatching(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('conditional', '/conditional', HandlerReference::fromString('builtin:openapi'), condition: 'context.getMethod() == "POST"', sourceId: 'fixture')], []);
        $compiled = new RouteMetadataCompiler()->compile($snapshot);
        self::assertCount(1, $compiled);
        $post = new WaaseyaaRouter(new RequestContext('', 'POST'), $snapshot);
        self::assertSame('conditional', $post->match('/conditional')['_route']);
        $this->expectException(RouteNotFoundException::class);
        new WaaseyaaRouter(new RequestContext('', 'GET'), $snapshot)->match('/conditional');
    }

    public function testUnsupportedConditionsAreExplicitlyRefused(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('unsupported', '/', HandlerReference::fromString('builtin:openapi'), condition: 'private_function()', sourceId: 'fixture')], []);
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('Unsupported route condition');
        new RouteMetadataCompiler()->compile($snapshot);
    }

    public function testApplicationRouteCompilerCannotBeActivated(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('unsupported', '/', HandlerReference::fromString('builtin:openapi'), options: ['compiler_class' => 'NeverLoad\\Compiler'], sourceId: 'fixture')], []);
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('Unsupported route compiler');
        new RouteMetadataCompiler()->compile($snapshot);
    }

    public function testPriorityAndStableTiesUseSymfonyOrdering(): void
    {
        $routes = [];
        foreach (['first' => 0, 'high' => 20, 'second' => 0, 'last' => -1] as $name => $priority) {
            $routes[] = new RouteDefinition($name, '/same', HandlerReference::fromString('builtin:openapi'), priority: $priority, sourceId: 'fixture', ordinal: count($routes));
        }
        $snapshot = new RouteSnapshot($routes, []);
        $compiled = new RouteMetadataCompiler()->compile($snapshot);
        self::assertSame(['high', 'first', 'second', 'last'], array_keys($compiled->all()));
        self::assertSame('high', new WaaseyaaRouter(snapshot: $snapshot)->match('/same')['_route']);
    }

    public function testCompiledMutationNeverChangesSnapshotOrAnotherConsumer(): void
    {
        $definition = new RouteDefinition('isolated', '/original', HandlerReference::fromString('builtin:openapi'), defaults: ['nested' => ['value' => 1]], sourceId: 'fixture');
        $snapshot = new RouteSnapshot([$definition], []);
        $compiler = new RouteMetadataCompiler();
        $first = $compiler->compile($snapshot);
        $first->get('isolated')->setPath('/changed');
        $first->get('isolated')->setDefault('nested', ['value' => 99]);
        $second = $compiler->compile($snapshot);
        self::assertSame('/original', $second->get('isolated')->getPath());
        self::assertSame(['value' => 1], $second->get('isolated')->getDefault('nested'));
        self::assertSame('/original', $snapshot->routes[0]->path);
        self::assertSame(['value' => 1], $snapshot->routes[0]->defaults['nested']);
    }

    public function testDuplicateSnapshotCannotReachSymfonyReplacement(): void
    {
        $route = new RouteDefinition('duplicate', '/', HandlerReference::fromString('builtin:openapi'), sourceId: 'fixture');
        $this->expectException(\InvalidArgumentException::class);
        new RouteSnapshot([$route, $route], []);
    }
}
