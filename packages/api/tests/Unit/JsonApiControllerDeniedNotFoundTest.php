<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * NFR-002 pin: a view-denied single read is byte-identical to a missing-entity
 * read — same probe id through two worlds (mission
 * request-surface-hardening-01KTX7F2, FR-003, contract discovery-and-404.md
 * clauses 9-12). If this test fails, someone forked the 404 detail string or
 * leaked the access result into the response.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerDeniedNotFoundTest extends TestCase
{
    #[Test]
    public function deniedShowIsByteIdenticalToMissingShowForTheSameId(): void
    {
        // World A: the entity exists but a policy denies 'view'.
        [$deniedController, $deniedStorage] = $this->createWorld(denyView: true);
        $entity = $deniedStorage->create(['title' => 'Secret']);
        $deniedStorage->save($entity);
        $probeId = $entity->id();

        // World B: same configuration, but the probe id was never created.
        [$missingController] = $this->createWorld(denyView: true);

        $denied = $deniedController->show('article', $probeId);
        $missing = $missingController->show('article', $probeId);

        self::assertSame(
            json_encode($missing->toArray(), JSON_THROW_ON_ERROR),
            json_encode($denied->toArray(), JSON_THROW_ON_ERROR),
            'Denied and missing single reads must be byte-identical for the same probe id (NFR-002).',
        );
        self::assertSame(404, $denied->statusCode);
        self::assertSame(404, $missing->statusCode);
    }

    #[Test]
    public function deniedShowCarriesNoCodeMemberAndNoDenialTrace(): void
    {
        [$controller, $storage] = $this->createWorld(denyView: true);
        $entity = $storage->create(['title' => 'Secret']);
        $storage->save($entity);

        $document = $controller->show('article', $entity->id());
        $array = $document->toArray();

        self::assertArrayHasKey('errors', $array);
        self::assertArrayNotHasKey('code', $array['errors'][0]);
        self::assertSame('404', $array['errors'][0]['status']);
        self::assertSame('Not Found', $array['errors'][0]['title']);

        $encoded = json_encode($array, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsStringIgnoringCase('denied', $encoded);
        self::assertStringNotContainsStringIgnoringCase('forbidden', $encoded);
        self::assertStringNotContainsString('No view access for testing.', $encoded);
        self::assertStringNotContainsString('Secret', $encoded, 'The denied entity must never be serialized.');
    }

    #[Test]
    public function allowedShowStillReturnsTheEntityResource(): void
    {
        [$controller, $storage] = $this->createWorld(denyView: false);
        $entity = $storage->create(['title' => 'Visible']);
        $storage->save($entity);

        $document = $controller->show('article', $entity->id());
        $array = $document->toArray();

        self::assertArrayNotHasKey('errors', $array);
        self::assertSame('Visible', $array['data']['attributes']['title']);
        self::assertSame(200, $document->statusCode);
    }

    // --- Helpers ---

    /**
     * Build an isolated controller world: registered 'article' type, in-memory
     * storage, real serializer and access handler, authenticated account.
     *
     * @return array{0: JsonApiController, 1: InMemoryEntityStorage}
     */
    private function createWorld(bool $denyView): array
    {
        $storage = new InMemoryEntityStorage('article');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            static fn() => $storage,
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        $policy = new class($denyView) implements AccessPolicyInterface {
            public function __construct(private readonly bool $denyView) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(\Waaseyaa\Entity\EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view' && $this->denyView) {
                    return AccessResult::forbidden('No view access for testing.');
                }

                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };

        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            new EntityAccessHandler([$policy]),
            $this->createAuthenticatedAccount(),
        );

        return [$controller, $storage];
    }

    private function createAuthenticatedAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
