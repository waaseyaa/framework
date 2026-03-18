<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
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
    protected PdoDatabase $database;
    protected EntityTypeManager $entityTypeManager;
    protected GraphQlEndpoint $endpoint;
    protected EntityAccessHandler $accessHandler;

    /** @var array<string, SqlEntityStorage> */
    protected array $storages = [];

    protected function setUp(): void
    {
        SchemaFactory::resetCache();

        $this->database = PdoDatabase::createSqlite();
        $eventDispatcher = new EventDispatcher();

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestArticle::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'text'],
                'author_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'author'],
                'related_article_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'article'],
            ],
        );

        $authorType = new EntityType(
            id: 'author',
            label: 'Author',
            class: TestAuthor::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'name' => ['type' => 'string', 'required' => true],
                'bio' => ['type' => 'text'],
                'secret' => ['type' => 'string'],
                'organization_id' => ['type' => 'entity_reference', 'target_entity_type_id' => 'organization'],
            ],
        );

        $organizationType = new EntityType(
            id: 'organization',
            label: 'Organization',
            class: TestOrganization::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'name' => ['type' => 'string', 'required' => true],
                'location' => ['type' => 'string'],
            ],
        );

        $types = ['article' => $articleType, 'author' => $authorType, 'organization' => $organizationType];

        foreach ($types as $id => $type) {
            $schemaHandler = new SqlSchemaHandler($type, $this->database);
            $schemaHandler->ensureTable();
            $this->storages[$id] = new SqlEntityStorage($type, $this->database, $eventDispatcher);
        }

        $storages = $this->storages;
        $this->entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            static fn(EntityTypeInterface $type) => $storages[$type->id()],
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

    protected function createAccount(int|string $id, array $roles = ['authenticated'], array $permissions = []): AccountInterface
    {
        return new class ($id, $roles, $permissions) implements AccountInterface {
            /** @param string[] $roles @param string[] $permissions */
            public function __construct(
                private readonly int|string $id,
                private readonly array $roles,
                private readonly array $permissions,
            ) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return $this->id !== 0;
            }
        };
    }
}
