<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NonNull;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * Tests GraphQL schema generation: input type separation (#439)
 * and pagination argument validation (#440).
 */
final class GraphQlSchemaValidationTest extends GraphQlIntegrationTestBase
{
    private \GraphQL\Type\Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $guard = new GraphQlAccessGuard($this->accessHandler, $this->createAccount(1));
        $referenceLoader = new ReferenceLoader($this->entityTypeManager, $guard, 3);
        $entityResolver = new EntityResolver($this->entityTypeManager, $guard);

        $factory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            entityResolver: $entityResolver,
            referenceLoader: $referenceLoader,
        );
        $this->schema = $factory->build();
    }

    // --- #439: Separate create/update input types ---

    public function testCreateInputHasNonNullOnRequiredFields(): void
    {
        $createInput = $this->getType('ArticleCreateInput');
        $this->assertInstanceOf(InputObjectType::class, $createInput);

        /** @var InputObjectType $createInput */
        $fields = $createInput->getFields();

        // 'title' is required in entity definition.
        $this->assertArrayHasKey('title', $fields);
        $this->assertInstanceOf(NonNull::class, $fields['title']->getType());
    }

    public function testUpdateInputHasAllFieldsNullable(): void
    {
        $updateInput = $this->getType('ArticleUpdateInput');
        $this->assertInstanceOf(InputObjectType::class, $updateInput);

        /** @var InputObjectType $updateInput */
        $fields = $updateInput->getFields();

        // 'title' is required for create, but nullable for update (PATCH semantics).
        $this->assertArrayHasKey('title', $fields);
        $this->assertNotInstanceOf(
            NonNull::class,
            $fields['title']->getType(),
            'Update input fields should be nullable for PATCH semantics',
        );
    }

    public function testCreateInputExcludesIdAndUuid(): void
    {
        $createInput = $this->getType('ArticleCreateInput');
        /** @var InputObjectType $createInput */
        $fields = $createInput->getFields();

        $this->assertArrayNotHasKey('id', $fields, 'id should be excluded from create input');
        $this->assertArrayNotHasKey('uuid', $fields, 'uuid should be excluded from create input');
    }

    public function testUpdateInputExcludesIdAndUuid(): void
    {
        $updateInput = $this->getType('ArticleUpdateInput');
        /** @var InputObjectType $updateInput */
        $fields = $updateInput->getFields();

        $this->assertArrayNotHasKey('id', $fields, 'id should be excluded from update input');
        $this->assertArrayNotHasKey('uuid', $fields, 'uuid should be excluded from update input');
    }

    public function testCreateMutationUsesCreateInput(): void
    {
        $result = GraphQL::executeQuery($this->schema, '
            { __schema { mutationType { fields { name args { name type { name kind ofType { name } } } } } } }
        ');
        $fields = $result->toArray()['data']['__schema']['mutationType']['fields'];

        $createArticle = $this->findField($fields, 'createArticle');
        $this->assertNotNull($createArticle);

        $inputArg = $this->findArg($createArticle['args'], 'input');
        $this->assertNotNull($inputArg);

        // input arg should be NonNull wrapping ArticleCreateInput.
        $this->assertSame('NON_NULL', $inputArg['type']['kind']);
        $this->assertSame('ArticleCreateInput', $inputArg['type']['ofType']['name']);
    }

    public function testUpdateMutationUsesUpdateInput(): void
    {
        $result = GraphQL::executeQuery($this->schema, '
            { __schema { mutationType { fields { name args { name type { name kind ofType { name } } } } } } }
        ');
        $fields = $result->toArray()['data']['__schema']['mutationType']['fields'];

        $updateArticle = $this->findField($fields, 'updateArticle');
        $this->assertNotNull($updateArticle);

        $inputArg = $this->findArg($updateArticle['args'], 'input');
        $this->assertNotNull($inputArg);

        $this->assertSame('NON_NULL', $inputArg['type']['kind']);
        $this->assertSame('ArticleUpdateInput', $inputArg['type']['ofType']['name']);
    }

    // --- #440: Pagination argument validation ---

    public function testListQueryHasLimitAndOffsetArgs(): void
    {
        $result = GraphQL::executeQuery($this->schema, '
            { __schema { queryType { fields { name args { name type { name kind } } } } } }
        ');
        $fields = $result->toArray()['data']['__schema']['queryType']['fields'];

        $articleList = $this->findField($fields, 'articleList');
        $this->assertNotNull($articleList, 'articleList query should exist');

        $limitArg = $this->findArg($articleList['args'], 'limit');
        $this->assertNotNull($limitArg, 'limit argument should exist');
        $this->assertSame('Int', $limitArg['type']['name']);

        $offsetArg = $this->findArg($articleList['args'], 'offset');
        $this->assertNotNull($offsetArg, 'offset argument should exist');
        $this->assertSame('Int', $offsetArg['type']['name']);
    }

    public function testDefaultLimitWhenOmitted(): void
    {
        // Seed enough articles to exceed default limit detection.
        // Default is 50, max is 100. With 2 seeded + 50 new = 52 total.
        for ($i = 0; $i < 50; $i++) {
            $article = $this->storages['article']->create(['title' => "Bulk {$i}"]);
            $this->storages['article']->save($article);
        }

        $response = $this->query('{ articleList { items { title } total } }');
        $this->assertNoErrors($response);

        $data = $response['data']['articleList'];
        // total reflects all articles (unfiltered count).
        $this->assertGreaterThan(50, $data['total']);
        // items should be capped at default limit (50), minus access-denied article 2.
        $this->assertLessThanOrEqual(50, count($data['items']));
    }

    public function testOffsetDefaultsToZero(): void
    {
        $response = $this->query('{ articleList(limit: 1) { items { id } total } }');
        $this->assertNoErrors($response);

        $data = $response['data']['articleList'];
        // First item should be article 1 (offset defaults to 0).
        $this->assertCount(1, $data['items']);
        $this->assertSame('1', $data['items'][0]['id']);
    }

    public function testMaxLimitIsCapped(): void
    {
        // Request limit=999, should be capped to MAX_LIMIT (100).
        $response = $this->query('{ articleList(limit: 999) { items { title } total } }');
        $this->assertNoErrors($response);

        // Should not error, limit is silently capped.
        $this->assertLessThanOrEqual(100, count($response['data']['articleList']['items']));
    }

    public function testNegativeOffsetTreatedAsZero(): void
    {
        $response = $this->query('{ articleList(offset: -5, limit: 1) { items { id } total } }');
        $this->assertNoErrors($response);

        // Negative offset should be clamped to 0.
        $this->assertCount(1, $response['data']['articleList']['items']);
    }

    // --- Helpers ---

    private function getType(string $name): mixed
    {
        return $this->schema->getType($name);
    }

    private function findField(array $fields, string $name): ?array
    {
        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    private function findArg(array $args, string $name): ?array
    {
        foreach ($args as $arg) {
            if ($arg['name'] === $name) {
                return $arg;
            }
        }
        return null;
    }
}
