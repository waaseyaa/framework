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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\DivergentWorkingCopyEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;

/**
 * #2788 independent review (critical): PATCH authorizes the WORKING COPY it
 * mutates, not the published pointer it happened to resolve first.
 *
 * With value-dependent policies (a generated blueprint policy reads the
 * owner and `workflow_state` authorization inputs), a disciplined
 * revisionable entity whose published revision and tip revision diverge
 * could previously authorize from the stale published inputs and then save
 * the different working revision. Both the entity-level `update` decision
 * and every field-level `edit` decision must see the exact target that is
 * written and saved, and nothing may be saved before those decisions.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerWorkingCopyAuthorizationTest extends TestCase
{
    private const int ACTOR = 5;

    private InMemoryEntityStorage $storage;
    private DivergentWorkingCopyEntityRepository $repository;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->repository = new DivergentWorkingCopyEntityRepository(new InMemoryEntityRepository($this->storage));
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => $this->repository,
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            // The write guard requires declared payload fields; `name` and
            // `phase` are the fixture's Public trait fields that carry the two
            // policy inputs below (the generated blueprint policies read their
            // Protected inputs through AuthorizationInputReader instead).
            _fieldDefinitions: [
                'body' => new FieldDefinition(name: 'body', type: 'text', read: FieldReadLevel::Public),
            ],
        ));

        // Value-dependent policy in the generated blueprint shape: an owner
        // reference (`name` holds the owning account id) and a state selector
        // (`phase`). Update is granted to the OWNER of the evaluated entity, and
        // `body` may not be edited while the evaluated entity is `published`.
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'update' && (string) $entity->get('name') === (string) $account->id()) {
                    return AccessResult::allowed('owner');
                }

                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'edit' && $fieldName === 'body' && $entity->get('phase') === 'published') {
                    return AccessResult::forbidden('published body is sealed');
                }

                return AccessResult::neutral();
            }
        };

        $this->controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            new EntityAccessHandler([$policy]),
            new AuthorizationPrincipal(self::ACTOR, true, [], [], 'working-copy-test'),
        );
    }

    #[Test]
    public function entityLevelUpdateIsDeniedWhenOnlyTheStalePublishedRevisionIsOwned(): void
    {
        $published = $this->seed(['title' => 'Published', 'body' => 'Published body', 'name' => self::ACTOR, 'phase' => 'draft']);
        $working = $this->workingCopyOf($published, ['name' => 99]);

        $doc = $this->patchBody($published, 'Attempted');

        self::assertSame(403, $doc->statusCode);
        self::assertSame('403', $doc->toArray()['errors'][0]['status']);
        self::assertSame(0, $this->repository->saves, 'nothing is saved before authorization');
        self::assertSame('Published body', $working->get('body'), 'the working copy is untouched');
    }

    #[Test]
    public function entityLevelUpdateIsGrantedWhenTheWorkingRevisionIsOwnedEvenThoughThePublishedOneIsNot(): void
    {
        $published = $this->seed(['title' => 'Published', 'body' => 'Published body', 'name' => 99, 'phase' => 'draft']);
        $working = $this->workingCopyOf($published, ['name' => self::ACTOR]);

        $doc = $this->patchBody($published, 'Edited on the tip');

        self::assertSame(200, $doc->statusCode);
        self::assertSame(1, $this->repository->saves);
        self::assertSame('Edited on the tip', $working->get('body'), 'the working copy received the write');
    }

    #[Test]
    public function fieldLevelEditIsDeniedAgainstTheWorkingRevisionState(): void
    {
        $published = $this->seed(['title' => 'Published', 'body' => 'Published body', 'name' => self::ACTOR, 'phase' => 'draft']);
        $working = $this->workingCopyOf($published, ['phase' => 'published']);

        $doc = $this->patchBody($published, 'Attempted');

        self::assertSame(403, $doc->statusCode);
        self::assertStringContainsString('body', $doc->toArray()['errors'][0]['detail']);
        self::assertSame(0, $this->repository->saves);
        self::assertSame('Published body', $working->get('body'));
    }

    #[Test]
    public function fieldLevelEditIsGrantedAgainstTheWorkingRevisionStateEvenThoughThePublishedOneIsSealed(): void
    {
        $published = $this->seed(['title' => 'Published', 'body' => 'Published body', 'name' => self::ACTOR, 'phase' => 'published']);
        $working = $this->workingCopyOf($published, ['phase' => 'draft']);

        $doc = $this->patchBody($published, 'Edited on the tip');

        self::assertSame(200, $doc->statusCode);
        self::assertSame('Edited on the tip', $working->get('body'));
    }

    /** An undisciplined entity (no divergent working copy) keeps today's behaviour. */
    #[Test]
    public function aNonRevisionableUpdateStillAuthorizesTheLoadedEntity(): void
    {
        $entity = $this->seed(['title' => 'Plain', 'body' => 'Plain body', 'name' => self::ACTOR, 'phase' => 'draft']);

        self::assertSame(200, $this->patchBody($entity, 'Edited')->statusCode);
        self::assertSame('Edited', $this->storage->load($entity->id())?->get('body'));

        $foreign = $this->seed(['title' => 'Foreign', 'body' => 'Foreign body', 'name' => 99, 'phase' => 'draft']);
        self::assertSame(403, $this->patchBody($foreign, 'Attempted')->statusCode);
    }

    /** @param array<string, mixed> $values */
    private function seed(array $values): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }

    /** @param array<string, mixed> $overrides */
    private function workingCopyOf(TestEntity $published, array $overrides): TestEntity
    {
        $working = $this->storage->create(array_merge([
            'id' => $published->id(),
            'uuid' => $published->uuid(),
            'title' => $published->get('title'),
            'body' => $published->get('body'),
            'name' => $published->get('name'),
            'phase' => $published->get('phase'),
        ], $overrides));
        $working->enforceIsNew(false);
        $this->repository->withWorkingCopy($published->id(), $working);

        return $working;
    }

    private function patchBody(TestEntity $entity, string $body): \Waaseyaa\Api\JsonApiDocument
    {
        return $this->controller->update('article', $entity->id(), [
            'data' => ['type' => 'article', 'id' => $entity->uuid(), 'attributes' => ['body' => $body]],
        ]);
    }
}
