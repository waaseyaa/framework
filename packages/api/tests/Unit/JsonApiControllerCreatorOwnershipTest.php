<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;
use Waaseyaa\Tests\Support\ClosedEntityValidatorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerCreatorOwnershipTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $database = $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                    $dispatcher,
                    database: $database,
                    validator: ClosedEntityValidatorFactory::create(Validation::createValidator()),
                    entityReferenceResolver: new EntityIdentifierResolver($this->entityTypeManager),
                );
            },
        );

        $this->entityTypeManager->registerEntityType(EntityType::fromClass(User::class));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'authored_article',
            label: 'Authored Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', required: true),
                'uid' => new FieldDefinition(
                    name: 'uid',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'user', 'authorizationInput' => true],
                    read: FieldReadLevel::Protected,
                ),
            ],
        ));

        $author = new User(['uid' => 42, 'name' => 'author', 'roles' => ['authenticated'], 'permissions' => []]);
        $author->enforceIsNew();
        $this->entityTypeManager->getRepository('user')->save($author, validate: false);
    }

    #[Test]
    public function devAdminSentinelDoesNotAutoAssignPhantomUidOnCreate(): void
    {
        $controller = $this->controller(new DevAdminAccount());
        $doc = $controller->store('authored_article', [
            'data' => [
                'type' => 'authored_article',
                'attributes' => ['title' => 'Dev-authored draft'],
            ],
        ]);

        self::assertSame(201, $doc->statusCode, json_encode($doc->toArray(), JSON_THROW_ON_ERROR));

        $stored = $this->database->getConnection()->fetchAssociative("SELECT json_extract(_data, '$.uid') AS uid FROM authored_article");
        self::assertIsArray($stored);
        self::assertNull($stored['uid'], 'Non-persisted principals must not create phantom user references.');
    }

    #[Test]
    public function persistedAuthenticatedUserReceivesCanonicalUidAttribution(): void
    {
        self::assertInstanceOf(User::class, $this->entityTypeManager->getRepository('user')->find('42'));

        $controller = $this->controller($this->principal(42));
        $doc = $controller->store('authored_article', [
            'data' => [
                'type' => 'authored_article',
                'attributes' => ['title' => 'Session-authored draft'],
            ],
        ]);

        self::assertSame(201, $doc->statusCode, json_encode($doc->toArray(), JSON_THROW_ON_ERROR));

        $stored = $this->database->getConnection()->fetchAssociative("SELECT json_extract(_data, '$.uid') AS uid FROM authored_article");
        self::assertIsArray($stored);
        self::assertSame(42, (int) $stored['uid']);
    }

    private function principal(int $id): AuthorizationPrincipalInterface
    {
        return new readonly class($id) implements AuthorizationPrincipalInterface {
            public function __construct(private int $id) {}

            public function id(): int { return $this->id; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['authenticated']; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }

    private function controller(AccountInterface $account): JsonApiController
    {
        return new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
            new EntityAccessHandler([new AllowAllPolicy()]),
            $account,
        );
    }
}

final class AllowAllPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('test fixture');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('test fixture');
    }
}
