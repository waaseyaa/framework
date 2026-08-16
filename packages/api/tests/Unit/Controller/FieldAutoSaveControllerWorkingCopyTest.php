<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4 item 5): the SAVE TARGET must be the
 * WORKING COPY, not the `find()`-loaded entity — same pattern as
 * `JsonApiController::update()`. Modeled with a repository whose `find()`
 * and `loadWorkingCopy()` return DIFFERENT objects, mirroring
 * `WorkflowTransitionControllerRevisionConflictTest`'s fixture shape.
 *
 * @covers \Waaseyaa\Api\Controller\FieldAutoSaveController
 */
#[CoversClass(FieldAutoSaveController::class)]
final class FieldAutoSaveControllerWorkingCopyTest extends TestCase
{
    #[Test]
    public function the_autosave_lands_on_the_working_copy_not_the_found_entity(): void
    {
        $foundEntity = $this->entity(id: 1, title: 'Published title');
        $workingCopy = $this->entity(id: 1, title: 'Draft title');

        $repository = new AutoSaveDivergentRepository($foundEntity, $workingCopy);
        $entityTypeManager = new class ($repository) implements EntityTypeManagerInterface {
            public function __construct(private readonly EntityRepositoryInterface $repository) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface { return new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id']); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface { return $this->repository; }
        };

        $allowAllPolicy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
        };
        $accessHandler = new EntityAccessHandler([$allowAllPolicy]);

        $fieldRegistry = new FieldDefinitionRegistry();
        $fieldRegistry->registerBundleFields('article', 'article', [
            new FieldDefinition(name: 'title', type: 'string', targetEntityTypeId: 'article', targetBundle: 'article', label: 'Title'),
        ]);

        $account = new AuthorizationPrincipal(1, true, ['administrator'], [], 'test');

        $controller = new FieldAutoSaveController($entityTypeManager, $accessHandler, $fieldRegistry);

        $request = Request::create(
            '/api/article/1/field/title',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['value' => 'Autosaved title'], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', $account);
        $request->headers->set('If-Match', $workingCopy->mutationToken()?->toStrongEtag() ?? '');

        $response = $controller->update($request, 'article', '1', 'title');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($workingCopy, $repository->savedEntity, 'save() must be called with the WORKING COPY object, not the found entity.');
        self::assertSame('Autosaved title', $workingCopy->get('title'), 'The new value must land on the WORKING COPY.');
        self::assertSame('Published title', $foundEntity->get('title'), 'The found (published) entity must be untouched.');

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Autosaved title', $body['data']['attributes']['title']);
    }

    private function entity(int $id, string $title): EntityInterface
    {
        $entity = new TestEntity(['id' => $id, 'uuid' => 'u-' . $id, 'title' => $title], 'article');
        $entity->enforceIsNew(false);
        $entity->_hydrateMutationToken(EntityMutationToken::issue('autosave-test', 'default', 'article', (string) $id, 1));

        return $entity;
    }
}

final class AutoSaveDivergentRepository implements EntityRepositoryInterface
{
    public ?EntityInterface $savedEntity = null;

    public function __construct(
        private readonly EntityInterface $foundEntity,
        private readonly EntityInterface $workingCopy,
    ) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->foundEntity; }
    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->workingCopy; }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->savedEntity = $entity;

        return 1;
    }

    public function delete(EntityInterface $entity): void {}
    public function exists(string $id): bool { return true; }
    public function count(array $criteria = []): int { return 0; }
    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
    public function rollback(string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
    public function listRevisions(string $entityId): array { return []; }
    public function setCurrentRevision(string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
    public function loadPublishedRevision(string $entityId): ?EntityInterface { return null; }
    public function setPublishedRevision(string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
    public function saveMany(array $entities, bool $validate = true): array { return []; }
    public function deleteMany(array $entities): int { return 0; }
    public function findTranslations(EntityInterface $entity): array { return []; }
    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int { return 0; }
    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
}
