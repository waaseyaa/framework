<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\OidcClientMemoryStorage;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * Unit coverage for the dedicated OIDC client mutation fence.
 *
 * HTTP-level agreement with the auto-generated JSON:API surface lives in
 * {@see \Waaseyaa\Api\Tests\Integration\OidcClientMutationFenceTest}
 * (`#[CoversNothing]`). ci/coverage records Clover from `#[CoversClass]`
 * tests, so this file is what ratchets the changed executable lines.
 */
#[CoversClass(OidcClientController::class)]
#[CoversClass(OidcClientApiRouter::class)]
final class OidcClientControllerMutationFenceTest extends TestCase
{
    private OidcClientMemoryStorage $storage;
    private InMemoryEntityRepository $repository;
    private OidcClientController $controller;
    private OidcClientApiRouter $router;

    protected function setUp(): void
    {
        $this->storage = new OidcClientMemoryStorage();
        $this->repository = new InMemoryEntityRepository($this->storage);
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            null,
            function (string $entityTypeId, EntityTypeInterface $definition): InMemoryEntityRepository {
                self::assertSame('oidc_client', $entityTypeId);
                self::assertSame(OidcClient::class, $definition->getClass());

                return $this->repository;
            },
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $this->controller = new OidcClientController($entityTypeManager);
        $this->router = new OidcClientApiRouter($this->controller);
    }

    #[Test]
    public function showEmitsTheCurrentMutationToken(): void
    {
        $id = $this->seedClient();

        $response = $this->controller->show($id);

        self::assertSame(200, $response->getStatusCode());
        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag);
        $token = EntityMutationToken::fromHttpIfMatch($etag);
        self::assertSame('oidc_client', $token->entityTypeId);
        self::assertSame($id, $token->entityId);
        $body = $this->decode($response);
        self::assertSame($token->toOpaqueString(), $body['meta']['mutation_token'] ?? null);
        self::assertSame('Minoo', $body['data']['name'] ?? null);
        self::assertArrayNotHasKey('client_secret', $body['data']);
    }

    #[Test]
    public function showOmitsEtagWhenTheLoadedEntityHasNoToken(): void
    {
        $client = new OidcClient([
            'client_id' => 'no-token',
            'name' => 'Bare',
        ]);
        $this->storage->save($client);
        $id = (string) $client->id();

        $response = $this->controller->show($id);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->headers->get('ETag'));
        self::assertArrayNotHasKey('meta', $this->decode($response));
    }

    #[Test]
    public function showReturns404ForUnknownId(): void
    {
        $response = $this->controller->show('999');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function patchWithoutIfMatchReturns428BeforeLoad(): void
    {
        $response = $this->controller->update('999', $this->request('PATCH', ['name' => 'ghost']));

        $this->assertJsonApiCode($response, 428, 'MUTATION_PRECONDITION_REQUIRED');
    }

    #[Test]
    public function patchWithBlankIfMatchReturns428(): void
    {
        $id = $this->seedClient();

        $response = $this->controller->update($id, $this->request('PATCH', ['name' => 'x'], '   '));

        $this->assertJsonApiCode($response, 428, 'MUTATION_PRECONDITION_REQUIRED');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedIfMatchValues(): iterable
    {
        yield 'weak' => ['W/"emt1.invalid"'];
        yield 'wildcard' => ['*'];
        yield 'quoted-wildcard' => ['"*"'];
        yield 'comma-list' => ['"one", "two"'];
        yield 'unquoted' => ['emt1.not-an-etag'];
        yield 'empty-quotes' => ['""'];
    }

    #[Test]
    #[DataProvider('malformedIfMatchValues')]
    public function patchWithMalformedIfMatchReturns400(string $ifMatch): void
    {
        $id = $this->seedClient();

        $response = $this->controller->update($id, $this->request('PATCH', ['name' => 'x'], $ifMatch));

        $this->assertJsonApiCode($response, 400, 'INVALID_MUTATION_PRECONDITION');
        self::assertSame('Minoo', $this->clientName($id));
    }

    #[Test]
    public function patchWithStaleTokenReturns412AndDoesNotPersist(): void
    {
        $id = $this->seedClient();
        $stale = $this->currentEtag($id);
        $this->advance($id, 'winner');

        $response = $this->controller->update($id, $this->request('PATCH', ['name' => 'loser'], $stale));

        $this->assertJsonApiCode($response, 412, 'MUTATION_PRECONDITION_FAILED');
        self::assertStringNotContainsString('emt1.', (string) $response->getContent());
        self::assertSame('winner', $this->clientName($id));
    }

    #[Test]
    public function patchWithForeignEntityIdReturns412(): void
    {
        $id = $this->seedClient();
        $foreign = EntityMutationToken::issue(
            'in-memory-test',
            'default',
            'oidc_client',
            'other-id',
            1,
        )->toStrongEtag();

        $response = $this->controller->update($id, $this->request('PATCH', ['name' => 'x'], $foreign));

        $this->assertJsonApiCode($response, 412, 'MUTATION_PRECONDITION_FAILED');
        self::assertSame('Minoo', $this->clientName($id));
    }

    #[Test]
    public function patchUnknownIdWithValidTokenReturns404(): void
    {
        $token = EntityMutationToken::issue(
            'in-memory-test',
            'default',
            'oidc_client',
            '999',
            1,
        )->toStrongEtag();

        $response = $this->controller->update('999', $this->request('PATCH', ['name' => 'x'], $token));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function patchWithInvalidJsonAfterFenceReturns400(): void
    {
        $id = $this->seedClient();
        $request = Request::create('/api/oidc-clients/' . $id, 'PATCH', content: '{');
        $request->headers->set('If-Match', $this->currentEtag($id));

        $response = $this->controller->update($id, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Minoo', $this->clientName($id));
    }

    #[Test]
    public function patchWithCurrentTokenSucceedsAndReturnsSuccessor(): void
    {
        $id = $this->seedClient();
        $etag = $this->currentEtag($id);

        $response = $this->controller->update($id, $this->request('PATCH', [
            'name' => 'renamed',
            'client_id' => 'minoo-web',
            'redirect_uris' => ['https://minoo.test/cb'],
            'scopes' => ['openid', 'profile'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => true,
        ], $etag));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('renamed', $this->clientName($id));
        $successor = $response->headers->get('ETag');
        self::assertNotNull($successor);
        self::assertNotSame($etag, $successor);
        EntityMutationToken::fromHttpIfMatch($successor);
    }

    #[Test]
    public function deleteWithoutIfMatchReturns428(): void
    {
        $id = $this->seedClient();

        $response = $this->controller->delete($id, $this->request('DELETE'));

        $this->assertJsonApiCode($response, 428, 'MUTATION_PRECONDITION_REQUIRED');
        self::assertNotNull($this->repository->find($id));
    }

    #[Test]
    public function deleteWithMalformedIfMatchReturns400(): void
    {
        $id = $this->seedClient();

        $response = $this->controller->delete($id, $this->request('DELETE', ifMatch: '*'));

        $this->assertJsonApiCode($response, 400, 'INVALID_MUTATION_PRECONDITION');
        self::assertNotNull($this->repository->find($id));
    }

    #[Test]
    public function deleteWithStaleTokenReturns412(): void
    {
        $id = $this->seedClient();
        $stale = $this->currentEtag($id);
        $this->advance($id, 'kept');

        $response = $this->controller->delete($id, $this->request('DELETE', ifMatch: $stale));

        $this->assertJsonApiCode($response, 412, 'MUTATION_PRECONDITION_FAILED');
        self::assertSame('kept', $this->clientName($id));
    }

    #[Test]
    public function deleteUnknownIdWithValidTokenReturns404(): void
    {
        $token = EntityMutationToken::issue(
            'in-memory-test',
            'default',
            'oidc_client',
            '999',
            1,
        )->toStrongEtag();

        $response = $this->controller->delete('999', $this->request('DELETE', ifMatch: $token));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteWithCurrentTokenReturns204(): void
    {
        $id = $this->seedClient();

        $response = $this->router->handle($this->routedRequest('DELETE', $id, ifMatch: $this->currentEtag($id)));

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->repository->find($id));
    }

    #[Test]
    public function routerDeleteForwardsTheRequestIntoTheFence(): void
    {
        $id = $this->seedClient();

        $response = $this->router->handle($this->routedRequest('DELETE', $id));

        $this->assertJsonApiCode($response, 428, 'MUTATION_PRECONDITION_REQUIRED');
        self::assertNotNull($this->repository->find($id));
    }

    private function seedClient(): string
    {
        $client = $this->repository->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
            'client_secret_hash' => 'hashed-secret',
        ]);
        $this->repository->save($client);

        return (string) $client->id();
    }

    private function advance(string $id, string $name): void
    {
        $client = $this->loadClient($id);
        $client->setName($name);
        $this->repository->save($client);
    }

    private function loadClient(string $id): OidcClient
    {
        $client = $this->repository->find($id);
        self::assertInstanceOf(OidcClient::class, $client);

        return $client;
    }

    private function clientName(string $id): string
    {
        return new OidcClientSystemReader()->registration($this->loadClient($id))->name;
    }

    private function currentEtag(string $id): string
    {
        $token = $this->loadClient($id)->mutationToken();
        self::assertNotNull($token);

        return $token->toStrongEtag();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, ?array $body = null, ?string $ifMatch = null): Request
    {
        $request = Request::create(
            '/api/oidc-clients/1',
            $method,
            content: $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }

        return $request;
    }

    private function routedRequest(string $method, string $id, ?string $ifMatch = null): Request
    {
        $request = $this->request($method, ifMatch: $ifMatch);
        $request->attributes->set('_controller', OidcClientController::class . '::delete');
        $request->attributes->set('id', $id);

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function assertJsonApiCode(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = $this->decode($response);
        self::assertSame('1.1', $body['jsonapi']['version'] ?? null);
        self::assertSame($code, $body['errors'][0]['code'] ?? null);
        self::assertArrayNotHasKey('meta', $body['errors'][0]);
    }
}
