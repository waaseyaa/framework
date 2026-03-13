<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase16;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Note\Note;
use Waaseyaa\Note\NoteAccessPolicy;
use Waaseyaa\Note\NoteServiceProvider;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

/**
 * Integration tests for the core.note entity type API layer.
 *
 * Covers:
 * - NoteServiceProvider registers the 'note' entity type
 * - POST /api/note with valid payload → 201
 * - DELETE /api/note/{id} → always 403 (core.note is non-deletable)
 */
#[CoversNothing]
final class NoteApiIntegrationTest extends TestCase
{
    private NoteInMemoryStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->storage = new NoteInMemoryStorage('note');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: Note::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        $this->accessHandler = new EntityAccessHandler([new NoteAccessPolicy()]);
    }

    // -----------------------------------------------------------------------
    // ServiceProvider
    // -----------------------------------------------------------------------

    #[Test]
    public function noteServiceProviderRegistersNoteEntityType(): void
    {
        $dispatcher = new EventDispatcher();
        $manager = new EntityTypeManager($dispatcher);

        $provider = new NoteServiceProvider();
        $provider->register();

        foreach ($provider->getEntityTypes() as $type) {
            $manager->registerEntityType($type);
        }

        $this->assertTrue($manager->hasDefinition('note'));
        $definition = $manager->getDefinition('note');
        $this->assertSame('Note', $definition->getLabel());
        $this->assertSame(Note::class, $definition->getClass());
    }

    // -----------------------------------------------------------------------
    // Store (valid payload)
    // -----------------------------------------------------------------------

    #[Test]
    public function storeWithValidPayloadReturns201(): void
    {
        $user = $this->makeUser(permissions: ['create note content']);
        $controller = $this->buildController($user);

        $doc = $controller->store('note', [
            'data' => [
                'type' => 'note',
                'attributes' => [
                    'title' => 'My First Note',
                    'body' => 'Hello, Waaseyaa.',
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('My First Note', $array['data']['attributes']['title']);
    }

    // -----------------------------------------------------------------------
    // Update access — NoteAccessPolicy returns neutral; deny-by-default applies
    // -----------------------------------------------------------------------

    #[Test]
    public function updateWithoutExplicitAllowReturns403(): void
    {
        // NoteAccessPolicy returns neutral for 'update'. With deny-by-default
        // semantics (isAllowed() = false unless a policy explicitly allows),
        // updates are blocked until a policy grants permission. This test
        // documents that contract explicitly.
        $note = $this->seedNote('Existing Note');
        $user = $this->makeUser(permissions: ['edit any note content']);
        $controller = $this->buildController($user);

        $doc = $controller->update('note', $note->id(), [
            'data' => [
                'type' => 'note',
                'id' => $note->uuid(),
                'attributes' => ['title' => 'Updated Title'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // DELETE guard — core.note is non-deletable
    // -----------------------------------------------------------------------

    #[Test]
    public function deleteByAnyUserReturns403(): void
    {
        $note = $this->seedNote('A Note');
        $user = $this->makeUser(permissions: ['delete any note content']);
        $controller = $this->buildController($user);

        $doc = $controller->destroy('note', $note->id());

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('403', $array['errors'][0]['status']);
        $this->assertSame('Forbidden', $array['errors'][0]['title']);
    }

    #[Test]
    public function deleteByAnonymousUserReturns403(): void
    {
        $note = $this->seedNote('Another Note');
        $controller = $this->buildController(new AnonymousUser());

        $doc = $controller->destroy('note', $note->id());

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function deleteByAdminStillReturns403(): void
    {
        $note = $this->seedNote('Admin Note');
        $admin = $this->makeUser(id: PHP_INT_MAX, permissions: ['administer notes', 'delete any note content']);
        $controller = $this->buildController($admin);

        $doc = $controller->destroy('note', $note->id());

        // core.note is unconditionally non-deletable
        $this->assertSame(403, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildController(User|AnonymousUser $account): JsonApiController
    {
        return new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            $this->accessHandler,
            $account,
        );
    }

    private function makeUser(int $id = 10, array $permissions = []): User
    {
        return new User([
            'uid' => $id,
            'name' => 'test_user',
            'permissions' => $permissions,
            'roles' => ['authenticated'],
        ]);
    }

    private function seedNote(string $title): Note
    {
        $note = new Note(['title' => $title]);
        $this->storage->save($note);

        return $note;
    }
}

/**
 * In-memory storage that creates Note entities.
 */
class NoteInMemoryStorage extends InMemoryEntityStorage
{
    /** @var array<int|string, Note> */
    private array $notes = [];
    private int $nextId = 1;

    public function create(array $values = []): Note
    {
        return new Note($values);
    }

    public function load(int|string $id): ?Note
    {
        return $this->notes[$id] ?? null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->notes;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($this->notes[$id])) {
                $result[$id] = $this->notes[$id];
            }
        }

        return $result;
    }

    public function save(EntityInterface $entity): int
    {
        $isNew = $entity->isNew();

        if ($isNew) {
            $id = $this->nextId++;
            $entity->set('id', $id);
            $entity->enforceIsNew(false);
        }

        $this->notes[$entity->id()] = $entity;

        return $isNew ? 1 : 2;
    }

    public function delete(array $entities): void
    {
        foreach ($entities as $entity) {
            unset($this->notes[$entity->id()]);
        }
    }

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        return new \Waaseyaa\Api\Tests\Fixtures\InMemoryEntityQuery(
            array_keys($this->notes),
            $this->notes,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'note';
    }
}
