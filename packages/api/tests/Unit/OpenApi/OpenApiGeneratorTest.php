<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\OpenApi;

use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\OpenApi\SchemaBuilder;
use Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Fixtures\UserNameContentTestEntity;
use Waaseyaa\Api\Tests\Fixtures\UserRoleContentTestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(OpenApiGenerator::class)]
final class OpenApiGeneratorTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
    }

    #[Test]
    public function generatedSpecHasCorrectOpenApiVersion(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
    }

    #[Test]
    public function infoBlockHasTitleVersionAndDescription(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertArrayHasKey('info', $spec);
        $this->assertSame('Waaseyaa API', $spec['info']['title']);
        $this->assertSame('0.1.0', $spec['info']['version']);
        $this->assertArrayHasKey('description', $spec['info']);
        $this->assertNotEmpty($spec['info']['description']);
    }

    #[Test]
    public function infoBlockUsesCustomTitleAndVersion(): void
    {
        $generator = new OpenApiGenerator(
            $this->entityTypeManager,
            title: 'My Custom API',
            version: '2.0.0',
        );
        $spec = $generator->generate();

        $this->assertSame('My Custom API', $spec['info']['title']);
        $this->assertSame('2.0.0', $spec['info']['version']);
    }

    #[Test]
    public function emptyEntityTypeManagerProducesValidSpec(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('schemas', $spec['components']);

        // Paths should be an empty stdClass (serializes to {} in JSON).
        $this->assertInstanceOf(\stdClass::class, $spec['paths']);
    }

    #[Test]
    public function emptySpecStillContainsSharedSchemas(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertArrayHasKey('JsonApiDocument', $spec['components']['schemas']);
        $this->assertArrayHasKey('JsonApiError', $spec['components']['schemas']);
        $this->assertArrayHasKey('JsonApiErrorDocument', $spec['components']['schemas']);
        $this->assertArrayHasKey('JsonApiVersion', $spec['components']['schemas']);
        $this->assertArrayHasKey('JsonApiLinks', $spec['components']['schemas']);
    }

    #[Test]
    public function registeredTypesWithoutApiOptInAreAbsentFromTheSpecification(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'fetch_log',
            label: 'Fetch log',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        $spec = (new OpenApiGenerator($this->entityTypeManager))->generate();

        self::assertInstanceOf(\stdClass::class, $spec['paths']);
        self::assertArrayNotHasKey('FetchLogResource', $spec['components']['schemas']);
    }

    #[Test]
    public function oneEntityTypeProducesFivePathOperations(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // Two path entries: /api/article and /api/article/{id}.
        $this->assertArrayHasKey('/api/article', $spec['paths']);
        $this->assertArrayHasKey('/api/article/{id}', $spec['paths']);

        // Collection path has GET and POST.
        $collectionPath = $spec['paths']['/api/article'];
        $this->assertArrayHasKey('get', $collectionPath);
        $this->assertArrayHasKey('post', $collectionPath);

        // Resource path has GET, PATCH, and DELETE.
        $resourcePath = $spec['paths']['/api/article/{id}'];
        $this->assertArrayHasKey('get', $resourcePath);
        $this->assertArrayHasKey('patch', $resourcePath);
        $this->assertArrayHasKey('delete', $resourcePath);
    }

    #[Test]
    public function multipleEntityTypesProducePathsForEach(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: UserNameContentTestEntity::class,
            keys: UserNameContentTestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // Article paths.
        $this->assertArrayHasKey('/api/article', $spec['paths']);
        $this->assertArrayHasKey('/api/article/{id}', $spec['paths']);

        // User paths.
        $this->assertArrayHasKey('/api/user', $spec['paths']);
        $this->assertArrayHasKey('/api/user/{id}', $spec['paths']);
    }

    #[Test]
    public function componentSchemasAreGeneratedPerEntityType(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $schemas = $spec['components']['schemas'];

        $this->assertArrayHasKey('ArticleResource', $schemas);
        $this->assertArrayHasKey('ArticleAttributes', $schemas);
        $this->assertArrayHasKey('ArticleCreateRequest', $schemas);
        $this->assertArrayHasKey('ArticleUpdateRequest', $schemas);
    }

    #[Test]
    public function schemasUseCorrectPascalCaseNaming(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user_role',
            label: 'User Role',
            class: UserRoleContentTestEntity::class,
            keys: UserRoleContentTestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $schemas = $spec['components']['schemas'];

        $this->assertArrayHasKey('UserRoleResource', $schemas);
        $this->assertArrayHasKey('UserRoleAttributes', $schemas);
        $this->assertArrayHasKey('UserRoleCreateRequest', $schemas);
        $this->assertArrayHasKey('UserRoleUpdateRequest', $schemas);
    }

    #[Test]
    public function pathsUseCorrectHttpMethods(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: NodeContentTestEntity::class,
            keys: NodeContentTestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // Collection: GET for list, POST for create.
        $collection = $spec['paths']['/api/node'];
        $this->assertCount(2, $collection);
        $this->assertArrayHasKey('get', $collection);
        $this->assertArrayHasKey('post', $collection);

        // Resource: GET for show, PATCH for update, DELETE for destroy.
        $resource = $spec['paths']['/api/node/{id}'];
        $this->assertCount(3, $resource);
        $this->assertArrayHasKey('get', $resource);
        $this->assertArrayHasKey('patch', $resource);
        $this->assertArrayHasKey('delete', $resource);
    }

    #[Test]
    public function responsesReferenceCorrectSchemas(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // GET collection references ArticleResource in items.
        $listResponse = $spec['paths']['/api/article']['get']['responses']['200'];
        $listSchema = $listResponse['content']['application/vnd.api+json']['schema'];
        $this->assertSame(
            '#/components/schemas/ArticleResource',
            $listSchema['properties']['data']['items']['$ref'],
        );

        // POST references create request and JsonApiDocument response.
        $createOp = $spec['paths']['/api/article']['post'];
        $this->assertSame(
            '#/components/schemas/ArticleCreateRequest',
            $createOp['requestBody']['content']['application/vnd.api+json']['schema']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/JsonApiDocument',
            $createOp['responses']['201']['content']['application/vnd.api+json']['schema']['$ref'],
        );

        // PATCH references update request.
        $updateOp = $spec['paths']['/api/article/{id}']['patch'];
        $this->assertSame(
            '#/components/schemas/ArticleUpdateRequest',
            $updateOp['requestBody']['content']['application/vnd.api+json']['schema']['$ref'],
        );
    }

    #[Test]
    public function deleteResponseIs204WithNoContent(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $deleteResponse = $spec['paths']['/api/article/{id}']['delete']['responses']['204'];

        $this->assertArrayHasKey('description', $deleteResponse);
        // 204 should not have content.
        $this->assertArrayNotHasKey('content', $deleteResponse);
    }

    #[Test]
    public function notFoundResponsesReferenceErrorSchema(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // GET single 404.
        $get404 = $spec['paths']['/api/article/{id}']['get']['responses']['404'];
        $this->assertSame(
            '#/components/schemas/JsonApiErrorDocument',
            $get404['content']['application/vnd.api+json']['schema']['$ref'],
        );

        // PATCH 404.
        $patch404 = $spec['paths']['/api/article/{id}']['patch']['responses']['404'];
        $this->assertSame(
            '#/components/schemas/JsonApiErrorDocument',
            $patch404['content']['application/vnd.api+json']['schema']['$ref'],
        );

        // DELETE 404.
        $delete404 = $spec['paths']['/api/article/{id}']['delete']['responses']['404'];
        $this->assertSame(
            '#/components/schemas/JsonApiErrorDocument',
            $delete404['content']['application/vnd.api+json']['schema']['$ref'],
        );
    }

    #[Test]
    public function validationErrorResponsesReferenceErrorSchema(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // POST 422.
        $post422 = $spec['paths']['/api/article']['post']['responses']['422'];
        $this->assertSame(
            '#/components/schemas/JsonApiErrorDocument',
            $post422['content']['application/vnd.api+json']['schema']['$ref'],
        );

        // PATCH 422.
        $patch422 = $spec['paths']['/api/article/{id}']['patch']['responses']['422'];
        $this->assertSame(
            '#/components/schemas/JsonApiErrorDocument',
            $patch422['content']['application/vnd.api+json']['schema']['$ref'],
        );
    }

    #[Test]
    public function customBasePathIsUsedInPaths(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator(
            $this->entityTypeManager,
            basePath: '/jsonapi',
        );
        $spec = $generator->generate();

        $this->assertArrayHasKey('/jsonapi/article', $spec['paths']);
        $this->assertArrayHasKey('/jsonapi/article/{id}', $spec['paths']);
        $this->assertArrayNotHasKey('/api/article', $spec['paths']);
    }

    #[Test]
    public function operationsHaveSummariesUsingEntityLabel(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertStringContainsString('Article', $spec['paths']['/api/article']['get']['summary']);
        $this->assertStringContainsString('Article', $spec['paths']['/api/article']['post']['summary']);
        $this->assertStringContainsString('Article', $spec['paths']['/api/article/{id}']['get']['summary']);
        $this->assertStringContainsString('Article', $spec['paths']['/api/article/{id}']['patch']['summary']);
        $this->assertStringContainsString('Article', $spec['paths']['/api/article/{id}']['delete']['summary']);
    }

    #[Test]
    public function operationsHaveOperationIds(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertSame('listArticle', $spec['paths']['/api/article']['get']['operationId']);
        $this->assertSame('createArticle', $spec['paths']['/api/article']['post']['operationId']);
        $this->assertSame('getArticle', $spec['paths']['/api/article/{id}']['get']['operationId']);
        $this->assertSame('updateArticle', $spec['paths']['/api/article/{id}']['patch']['operationId']);
        $this->assertSame('deleteArticle', $spec['paths']['/api/article/{id}']['delete']['operationId']);
    }

    #[Test]
    public function operationsHaveTagsMatchingEntityLabel(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertSame(['Article'], $spec['paths']['/api/article']['get']['tags']);
        $this->assertSame(['Article'], $spec['paths']['/api/article']['post']['tags']);
        $this->assertSame(['Article'], $spec['paths']['/api/article/{id}']['get']['tags']);
    }

    #[Test]
    public function resourcePathsHaveIdParameter(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        // GET single should have id parameter.
        $getParams = $spec['paths']['/api/article/{id}']['get']['parameters'];
        $this->assertCount(1, $getParams);
        $this->assertSame('id', $getParams[0]['name']);
        $this->assertSame('path', $getParams[0]['in']);
        $this->assertTrue($getParams[0]['required']);

        // PATCH should have id parameter.
        $patchParams = $spec['paths']['/api/article/{id}']['patch']['parameters'];
        $this->assertSame('id', $patchParams[0]['name']);

        // DELETE should have id parameter.
        $deleteParams = $spec['paths']['/api/article/{id}']['delete']['parameters'];
        $this->assertSame('id', $deleteParams[0]['name']);
    }

    #[Test]
    public function jsonApiErrorSchemaHasRequiredFields(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $errorSchema = $spec['components']['schemas']['JsonApiError'];

        $this->assertSame('object', $errorSchema['type']);
        $this->assertContains('status', $errorSchema['required']);
        $this->assertContains('title', $errorSchema['required']);
        $this->assertArrayHasKey('status', $errorSchema['properties']);
        $this->assertArrayHasKey('title', $errorSchema['properties']);
        $this->assertArrayHasKey('detail', $errorSchema['properties']);
        $this->assertArrayHasKey('source', $errorSchema['properties']);
    }

    #[Test]
    public function jsonApiDocumentSchemaHasCorrectStructure(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $docSchema = $spec['components']['schemas']['JsonApiDocument'];

        $this->assertSame('object', $docSchema['type']);
        $this->assertArrayHasKey('data', $docSchema['properties']);
        $this->assertArrayHasKey('meta', $docSchema['properties']);
        $this->assertArrayHasKey('links', $docSchema['properties']);
        $this->assertArrayHasKey('included', $docSchema['properties']);
        $this->assertArrayHasKey('jsonapi', $docSchema['properties']);
    }

    #[Test]
    public function multipleEntityTypesProduceSeparateSchemas(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: UserNameContentTestEntity::class,
            keys: UserNameContentTestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $schemas = $spec['components']['schemas'];

        // Article schemas.
        $this->assertArrayHasKey('ArticleResource', $schemas);
        $this->assertArrayHasKey('ArticleAttributes', $schemas);
        $this->assertArrayHasKey('ArticleCreateRequest', $schemas);
        $this->assertArrayHasKey('ArticleUpdateRequest', $schemas);

        // User schemas.
        $this->assertArrayHasKey('UserResource', $schemas);
        $this->assertArrayHasKey('UserAttributes', $schemas);
        $this->assertArrayHasKey('UserCreateRequest', $schemas);
        $this->assertArrayHasKey('UserUpdateRequest', $schemas);

        // Shared schemas still present.
        $this->assertArrayHasKey('JsonApiDocument', $schemas);
        $this->assertArrayHasKey('JsonApiError', $schemas);
    }

    #[Test]
    public function specSerializesToValidJson(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $json = json_encode($spec, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
        $this->assertIsString($json);

        // Verify it can be decoded back.
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('3.1.0', $decoded['openapi']);
    }

    #[Test]
    public function emptyPathsSerializeToJsonObject(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $json = json_encode($spec, \JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Empty paths should be {} not [].
        $this->assertIsArray($decoded['paths']);
        $this->assertEmpty($decoded['paths']);
    }

    #[Test]
    public function postRequestBodyIsRequired(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $postOp = $spec['paths']['/api/article']['post'];
        $this->assertTrue($postOp['requestBody']['required']);
    }

    #[Test]
    public function patchRequestBodyIsRequired(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        api: true,
        ));

        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $patchOp = $spec['paths']['/api/article/{id}']['patch'];
        $this->assertTrue($patchOp['requestBody']['required']);
    }
}
