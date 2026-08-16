<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestArticle;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestAuthor;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestOrganization;
use Waaseyaa\Tests\Integration\GraphQL\Policy\AllowAllPolicy;
use Waaseyaa\Tests\Integration\GraphQL\Policy\DenyByIdPolicy;
use Waaseyaa\Tests\Integration\GraphQL\Policy\RestrictFieldPolicy;

abstract class GraphQlIntegrationTestBase extends TestCase
{
    protected DBALDatabase $database;
    protected EntityTypeManager $entityTypeManager;
    protected GraphQlEndpoint $endpoint;
    protected EntityAccessHandler $accessHandler;

    /** @var array<string, EntityRepository> */
    protected array $storages = [];

    protected function setUp(): void
    {
        SchemaFactory::resetCache();

        $this->database = DBALDatabase::createSqlite();
        $eventDispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestArticle::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            _fieldDefinitions: [
                'id' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'uuid' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'title' => ['type' => 'string', 'required' => true, 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                'author_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'author', 'read' => FieldReadLevel::Public],
                'related_article_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'article', 'read' => FieldReadLevel::Public],
            ],
        );

        $authorType = new EntityType(
            id: 'author',
            label: 'Author',
            class: TestAuthor::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            _fieldDefinitions: [
                'id' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'uuid' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'name' => ['type' => 'string', 'required' => true, 'read' => FieldReadLevel::Public],
                'bio' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                // This fixture exercises the legacy GraphQL surface-policy filter;
                // entity-layer Protected behavior has dedicated activation tests.
                'secret' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'organization_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'organization', 'read' => FieldReadLevel::Public],
            ],
        );

        $organizationType = new EntityType(
            id: 'organization',
            label: 'Organization',
            class: TestOrganization::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            _fieldDefinitions: [
                'id' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'uuid' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'name' => ['type' => 'string', 'required' => true, 'read' => FieldReadLevel::Public],
                'location' => ['type' => 'string', 'read' => FieldReadLevel::Public],
            ],
        );

        $types = ['article' => $articleType, 'author' => $authorType, 'organization' => $organizationType];

        foreach ($types as $id => $type) {
            $schemaHandler = new SqlSchemaHandler($type, $this->database);
            $schemaHandler->ensureTable();
            // Wire the access handler into getQuery() the way production does
            // (AbstractKernel / EntityRepository factory, issue #1714). Lazily, since
            // $this->accessHandler is built below after seeding. Without this the
            // query layer falls back to an empty handler and — under deny-by-default
            // (audit C-6) — denies every row before the resolver's guard runs.
            $this->storages[$id] = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $this->database,
                accessHandlerResolver: fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
            );
        }

        $storages = $this->storages;
        $database = $this->database;
        $this->entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            static fn(EntityTypeInterface $type) => $storages[$type->id()],
            // C-22: repository factory mirroring the kernel's getRepository() shape
            // — same lazy accessHandlerResolver the storage factory threads above.
            fn(string $_id, EntityTypeInterface $type): EntityRepository => \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $database,
                accessHandlerResolver: fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
            ),
        );

        foreach ($types as $type) {
            $this->entityTypeManager->registerEntityType($type);
        }

        $this->seedData();

        $this->accessHandler = new EntityAccessHandler([
            new AllowAllPolicy(),
            new DenyByIdPolicy('article', 2),
            new RestrictFieldPolicy('author', 'secret'),
        ]);

        $this->endpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $this->accessHandler,
            $this->createAccount(1),
        );
    }

    protected function seedData(): void
    {
        $org = $this->storages['organization']->create(['name' => 'Acme', 'location' => 'NYC']);
        $this->storages['organization']->save($org);

        $alice = $this->storages['author']->create([
            'name' => 'Alice', 'bio' => 'Writer', 'secret' => 'classified', 'organization_id' => 1,
        ]);
        $this->storages['author']->save($alice);

        $bob = $this->storages['author']->create([
            'name' => 'Bob', 'bio' => 'Editor', 'secret' => 'redacted', 'organization_id' => 1,
        ]);
        $this->storages['author']->save($bob);

        $article1 = $this->storages['article']->create([
            'title' => 'Hello', 'body' => 'Content 1', 'author_id' => 1, 'related_article_id' => 2,
        ]);
        $this->storages['article']->save($article1);

        $article2 = $this->storages['article']->create([
            'title' => 'World', 'body' => 'Content 2', 'author_id' => 2, 'related_article_id' => 1,
        ]);
        $this->storages['article']->save($article2);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    protected function query(string $graphql, array $variables = []): array
    {
        $body = json_encode(['query' => $graphql, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $result = $this->endpoint->handle('POST', $body);

        return $result['body'];
    }

    /** @param array<string, mixed> $response */
    protected function assertNoErrors(array $response): void
    {
        $this->assertArrayNotHasKey('errors', $response, sprintf(
            'GraphQL response contained errors: %s',
            isset($response['errors']) ? json_encode($response['errors'], JSON_PRETTY_PRINT) : 'none',
        ));
    }

    /** @param array<string, mixed> $response */
    protected function assertHasError(array $response, string $messageFragment): void
    {
        $this->assertArrayHasKey('errors', $response, 'Expected GraphQL errors but none found');
        $messages = array_map(
            static fn(array $error): string => $error['message'] ?? '',
            $response['errors'],
        );
        $found = false;
        foreach ($messages as $message) {
            if (str_contains($message, $messageFragment)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, sprintf(
            'Expected error containing "%s" but got: %s',
            $messageFragment,
            implode(', ', $messages),
        ));
    }

    protected function mutationToken(string $entityType, int|string $id): string
    {
        $entity = $this->storages[$entityType]->find((string) $id);
        self::assertInstanceOf(EntityBase::class, $entity);
        $token = $entity->mutationToken()?->toOpaqueString();
        self::assertNotNull($token);

        return $token;
    }

    protected function createAccount(int|string $id, array $roles = ['authenticated'], array $permissions = []): AccountInterface
    {
        return new AuthorizationPrincipal($id, $id !== 0, $roles, $permissions, 'test-' . (string) $id);
    }
}
