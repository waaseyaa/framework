<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\OidcClientMemoryStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(OidcClientApiRouter::class)]
final class OidcTerminalBindingTest extends TestCase
{
    public function testSixActionsPreserveResponsesSecretsMutationFencesAndMatchedId(): void
    {
        foreach ([
            ['index', null, '', false, 200], ['show', '1', '', false, 200],
            ['show', 'missing', '', false, 404], ['show', ['invalid'], '', false, 404],
            ['create', null, 'invalid', false, 400], ['create', null, '{"client_id":"new","name":"New"}', false, 201],
            ['update', '1', '{"name":"Updated"}', false, 428], ['update', '1', '{"name":"Updated"}', true, 200],
            ['delete', '1', '', false, 428], ['delete', '1', '', true, 204],
            ['regenerateSecret', 'missing', '', false, 404], ['regenerateSecret', '1', '', false, 200],
        ] as [$action, $id, $body, $fence, $status]) {
            $responses = [];
            $repositories = [];
            foreach ([false, true] as $explicit) {
                [$manager, $repository] = $this->world();
                $repositories[] = $repository;
                $request = new Request(attributes: in_array($action, ['show', 'update', 'delete', 'regenerateSecret'], true) ? ['id' => $id] : [], content: $body);
                if ($fence) {
                    $request->headers->set('If-Match', $repository->find('1')->mutationToken()->toStrongEtag());
                }
                if (!$explicit) {
                    $request->attributes->set('_controller', OidcClientController::class . '::' . $action);
                    $responses[] = new ControllerDispatcher([new OidcClientApiRouter(new OidcClientController($manager))])->dispatch($request);
                    continue;
                }
                [$provider, $bus] = $this->provider($manager);
                $definition = new RouteDefinition('fixture.oidc', '/fixture', HandlerReference::fromString('class:' . OidcClientApiRouter::class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
                $request->attributes->set('_route', $definition->name);
                $request->attributes->set('_controller', $definition->handler->id);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                self::assertTrue($services->has(OidcClientApiRouter::class));
                self::assertSame([], $bus->reads);
                $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
                $request->attributes->set('_controller', $handler);
                $responses[] = new ControllerDispatcher([])->dispatch($request);
                self::assertNotSame($provider->resolve(OidcClientApiRouter::class), $provider->resolve(OidcClientApiRouter::class));
            }
            foreach ($responses as $response) {
                self::assertSame($status, $response->getStatusCode());
            }
            foreach (['Content-Type', 'Cache-Control'] as $header) {
                self::assertSame($responses[0]->headers->get($header), $responses[1]->headers->get($header));
            }
            self::assertSame($responses[0]->headers->has('ETag'), $responses[1]->headers->has('ETag'));
            $payloads = [];
            foreach ($responses as $i => $response) {
                if ($status === 204) {
                    self::assertNull($repositories[$i]->find('1'));
                    $payloads[] = $response->getContent();
                    continue;
                }
                $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                if ($response->headers->has('ETag')) {
                    $token = $repositories[$i]->find('1')->mutationToken();
                    self::assertSame($token->toStrongEtag(), $response->headers->get('ETag'));
                    self::assertSame($token->toOpaqueString(), $payload['meta']['mutation_token']);
                    // Separate test repositories mint independent authority generations.
                    $payload['meta']['mutation_token'] = '<verified-current-authority>';
                }
                if (($action === 'create' && $status === 201) || ($action === 'regenerateSecret' && $status === 200)) {
                    $secret = $payload['data']['client_secret'];
                    self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
                    self::assertTrue(new OidcClientSystemReader()->verifySecret($repositories[$i]->find($payload['data']['id']), $secret));
                    unset($payload['data']['client_secret']);
                } else {
                    self::assertStringNotContainsString('client_secret', $response->getContent());
                }
                $payloads[] = $payload;
            }
            self::assertSame($payloads[0], $payloads[1]);
        }
    }

    public function testRequiredManagerFailureRefusesSelection(): void
    {
        foreach ([null, new \stdClass(), new \RuntimeException('private manager detail')] as $binding) {
            [$provider, $bus] = $this->provider($binding);
            $definition = new RouteDefinition('fixture.oidc', '/fixture', HandlerReference::fromString('class:' . OidcClientApiRouter::class . '::index'), sourceId: 'fixture.api', ordinal: 0);
            $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has(OidcClientApiRouter::class));
            self::assertSame([], $bus->reads);
            try {
                new RouteHandlerResolver($definition, $services)->resolveMatched();
                self::fail('An unhealthy manager must refuse.');
            } catch (HandlerResolutionException $error) {
                self::assertStringContainsString('resolution-failed', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    public function testExplicitCallableArgumentCannotReplaceMatchedId(): void
    {
        [$manager] = $this->world();
        [$provider] = $this->provider($manager);
        $definition = new RouteDefinition('fixture.oidc', '/fixture/{id}', HandlerReference::fromString('class:' . OidcClientApiRouter::class . '::show'), sourceId: 'fixture.api', ordinal: 0);
        $request = new Request(attributes: ['id' => '1', '_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
        self::assertSame(200, $callable($request, id: 'missing')->getStatusCode());
    }

    private function world(): array
    {
        $storage = new OidcClientMemoryStorage();
        $repository = new InMemoryEntityRepository($storage);
        $manager = new EntityTypeManager(new EventDispatcher(), repositoryFactory: static fn() => $repository);
        $manager->registerEntityType(new EntityType('oidc_client', 'Clients', OidcClient::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name']));
        $repository->save($repository->create(['uuid' => '00000000-0000-0000-0000-000000000001', 'client_id' => 'fixture', 'name' => 'Fixture', 'client_secret_hash' => 'fixture-hash']));
        return [$manager, $repository];
    }

    private function provider(?object $manager): array
    {
        $bus = new class ($manager) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private ?object $manager) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                if ($abstract !== EntityTypeManager::class) {
                    return null;
                }
                if ($this->manager instanceof \Throwable) {
                    throw $this->manager;
                }
                return $this->manager;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }
}
