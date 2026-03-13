<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase18;

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
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

/**
 * Integration tests for #199 — baseline RBAC on core.note.
 *
 * Covers:
 * - tenant.member  : view → 200, create → 403, update → 403
 * - tenant.admin   : view → 200, create → 201, update → 200
 * - platform.admin : view → 200, create → 201, update → 200
 * - anonymous      : view → 403, create → 403
 * - System field edit guard: forbidden for tenant.admin, allowed for platform.admin
 */
#[CoversNothing]
final class NoteRbacIntegrationTest extends TestCase
{
    private NoteRbacStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->storage = new NoteRbacStorage('note');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: Note::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'id'         => ['type' => 'integer', 'label' => 'ID'],
                'uuid'       => ['type' => 'string',  'label' => 'UUID'],
                'title'      => ['type' => 'string',  'label' => 'Title'],
                'body'       => ['type' => 'string',  'label' => 'Body'],
                'created_at' => ['type' => 'string',  'label' => 'Created At'],
                'updated_at' => ['type' => 'string',  'label' => 'Updated At'],
            ],
        ));

        $this->accessHandler = new EntityAccessHandler([new NoteAccessPolicy()]);
    }

    // -----------------------------------------------------------------------
    // tenant.member
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantMemberCanViewNote(): void
    {
        $note = $this->seedNote('Member View Test');
        $user = $this->makeUser(roles: ['tenant.member']);
        $controller = $this->buildController($user);

        $doc = $controller->show('note', $note->id());

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function tenantMemberCannotCreateNote(): void
    {
        $user = $this->makeUser(roles: ['tenant.member']);
        $controller = $this->buildController($user);

        $doc = $controller->store('note', [
            'data' => ['type' => 'note', 'attributes' => ['title' => 'Nope']],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function tenantMemberCannotUpdateNote(): void
    {
        $note = $this->seedNote('Member Update Test');
        $user = $this->makeUser(roles: ['tenant.member']);
        $controller = $this->buildController($user);

        $doc = $controller->update('note', $note->id(), [
            'data' => ['type' => 'note', 'id' => $note->uuid(), 'attributes' => ['title' => 'Updated']],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // tenant.admin
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantAdminCanViewNote(): void
    {
        $note = $this->seedNote('Admin View Test');
        $user = $this->makeUser(roles: ['tenant.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->show('note', $note->id());

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function tenantAdminCanCreateNote(): void
    {
        $user = $this->makeUser(roles: ['tenant.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->store('note', [
            'data' => ['type' => 'note', 'attributes' => ['title' => 'New Note']],
        ]);

        $this->assertSame(201, $doc->statusCode);
    }

    #[Test]
    public function tenantAdminCanUpdateNote(): void
    {
        $note = $this->seedNote('Admin Update Test');
        $user = $this->makeUser(roles: ['tenant.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->update('note', $note->id(), [
            'data' => ['type' => 'note', 'id' => $note->uuid(), 'attributes' => ['title' => 'Updated']],
        ]);

        $this->assertSame(200, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // platform.admin
    // -----------------------------------------------------------------------

    #[Test]
    public function platformAdminCanViewNote(): void
    {
        $note = $this->seedNote('Platform View Test');
        $user = $this->makeUser(id: PHP_INT_MAX, roles: ['platform.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->show('note', $note->id());

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function platformAdminCanCreateNote(): void
    {
        $user = $this->makeUser(id: PHP_INT_MAX, roles: ['platform.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->store('note', [
            'data' => ['type' => 'note', 'attributes' => ['title' => 'Platform Note']],
        ]);

        $this->assertSame(201, $doc->statusCode);
    }

    #[Test]
    public function platformAdminCanUpdateNote(): void
    {
        $note = $this->seedNote('Platform Update Test');
        $user = $this->makeUser(id: PHP_INT_MAX, roles: ['platform.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->update('note', $note->id(), [
            'data' => ['type' => 'note', 'id' => $note->uuid(), 'attributes' => ['title' => 'Updated']],
        ]);

        $this->assertSame(200, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // anonymous
    // -----------------------------------------------------------------------

    #[Test]
    public function anonymousCannotViewNote(): void
    {
        $note = $this->seedNote('Anon View Test');
        $controller = $this->buildController(new AnonymousUser());

        $doc = $controller->show('note', $note->id());

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function anonymousCannotCreateNote(): void
    {
        $controller = $this->buildController(new AnonymousUser());

        $doc = $controller->store('note', [
            'data' => ['type' => 'note', 'attributes' => ['title' => 'Anon']],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // DELETE — always forbidden (verified in Phase16; one regression guard here)
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantAdminCannotDeleteNote(): void
    {
        $note = $this->seedNote('Delete Guard Test');
        $user = $this->makeUser(roles: ['tenant.admin']);
        $controller = $this->buildController($user);

        $doc = $controller->destroy('note', $note->id());

        $this->assertSame(403, $doc->statusCode);
    }

    // -----------------------------------------------------------------------
    // Field-level access via EntityAccessHandler
    // -----------------------------------------------------------------------

    #[Test]
    public function tenantAdminCannotEditSystemFields(): void
    {
        $note = $this->seedNote('Field Test');
        $user = $this->makeUser(roles: ['tenant.admin']);

        foreach (['id', 'uuid', 'created_at', 'updated_at'] as $field) {
            $result = $this->accessHandler->checkFieldAccess($note, $field, 'edit', $user);
            $this->assertTrue($result->isForbidden(), "Expected '$field' edit to be forbidden for tenant.admin");
        }
    }

    #[Test]
    public function platformAdminCanEditSystemFields(): void
    {
        $note = $this->seedNote('Platform Field Test');
        $user = $this->makeUser(id: PHP_INT_MAX, roles: ['platform.admin']);

        foreach (['id', 'uuid', 'created_at', 'updated_at'] as $field) {
            $result = $this->accessHandler->checkFieldAccess($note, $field, 'edit', $user);
            $this->assertFalse($result->isForbidden(), "Expected '$field' edit to be allowed for platform.admin");
        }
    }

    #[Test]
    public function tenantAdminCanEditUserFields(): void
    {
        $note = $this->seedNote('User Field Test');
        $user = $this->makeUser(roles: ['tenant.admin']);

        foreach (['title', 'body'] as $field) {
            $result = $this->accessHandler->checkFieldAccess($note, $field, 'edit', $user);
            $this->assertFalse($result->isForbidden(), "Expected '$field' edit to be allowed for tenant.admin");
        }
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

    private function makeUser(int $id = 10, array $roles = [], array $permissions = []): User
    {
        return new User([
            'uid'         => $id,
            'name'        => 'test_user',
            'permissions' => $permissions,
            'roles'       => $roles,
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
class NoteRbacStorage extends InMemoryEntityStorage
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
