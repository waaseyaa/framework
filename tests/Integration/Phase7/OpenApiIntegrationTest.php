<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Node\Node;
use Waaseyaa\Taxonomy\Term;

/**
 * OpenAPI spec generation integration tests with real entity types.
 *
 * Exercises: waaseyaa/api (OpenApiGenerator, SchemaBuilder) with
 * waaseyaa/entity (EntityTypeManager, EntityType), waaseyaa/node (Node),
 * and waaseyaa/taxonomy (Term).
 */
#[CoversNothing]
final class OpenApiIntegrationTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
        );
    }

    private function registerNodeType(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: Node::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        ));
    }

    private function registerTaxonomyTermType(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'taxonomy_term',
            label: 'Taxonomy Term',
            class: Term::class,
            keys: [
                'id' => 'tid',
                'uuid' => 'uuid',
                'label' => 'name',
                'bundle' => 'vid',
            ],
        ));
    }

    #[Test]
    public function generatesSpecWithCorrectOpenApiVersion(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
    }

    #[Test]
    public function generatesSpecWithInfoBlock(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertSame('Waaseyaa API', $spec['info']['title']);
    }

    #[Test]
    public function generatesPathsForNodeEntityType(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        $this->assertArrayHasKey('/api/node', $spec['paths']);
        $this->assertArrayHasKey('/api/node/{id}', $spec['paths']);
    }

    #[Test]
    public function generatesPathsForBothEntityTypes(): void
    {
        $this->registerNodeType();
        $this->registerTaxonomyTermType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        // Node paths.
        $this->assertArrayHasKey('/api/node', $spec['paths']);
        $this->assertArrayHasKey('/api/node/{id}', $spec['paths']);

        // Taxonomy term paths.
        $this->assertArrayHasKey('/api/taxonomy_term', $spec['paths']);
        $this->assertArrayHasKey('/api/taxonomy_term/{id}', $spec['paths']);
    }

    #[Test]
    public function collectionPathHasGetAndPostMethods(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $collectionPath = $spec['paths']['/api/node'];

        $this->assertArrayHasKey('get', $collectionPath);
        $this->assertArrayHasKey('post', $collectionPath);
    }

    #[Test]
    public function resourcePathHasGetPatchDeleteMethods(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $resourcePath = $spec['paths']['/api/node/{id}'];

        $this->assertArrayHasKey('get', $resourcePath);
        $this->assertArrayHasKey('patch', $resourcePath);
        $this->assertArrayHasKey('delete', $resourcePath);
    }

    #[Test]
    public function componentSchemasExistForEachEntityType(): void
    {
        $this->registerNodeType();
        $this->registerTaxonomyTermType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $schemas = $spec['components']['schemas'];

        // Node schemas (PascalCase: Node).
        $this->assertArrayHasKey('NodeResource', $schemas);
        $this->assertArrayHasKey('NodeAttributes', $schemas);
        $this->assertArrayHasKey('NodeCreateRequest', $schemas);
        $this->assertArrayHasKey('NodeUpdateRequest', $schemas);

        // TaxonomyTerm schemas (PascalCase: TaxonomyTerm).
        $this->assertArrayHasKey('TaxonomyTermResource', $schemas);
        $this->assertArrayHasKey('TaxonomyTermAttributes', $schemas);
        $this->assertArrayHasKey('TaxonomyTermCreateRequest', $schemas);
        $this->assertArrayHasKey('TaxonomyTermUpdateRequest', $schemas);
    }

    #[Test]
    public function sharedSchemasExist(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $schemas = $spec['components']['schemas'];

        $this->assertArrayHasKey('JsonApiDocument', $schemas);
        $this->assertArrayHasKey('JsonApiErrorDocument', $schemas);
        $this->assertArrayHasKey('JsonApiError', $schemas);
        $this->assertArrayHasKey('JsonApiVersion', $schemas);
        $this->assertArrayHasKey('JsonApiLinks', $schemas);
    }

    #[Test]
    public function specIsValidJsonWhenEncoded(): void
    {
        $this->registerNodeType();
        $this->registerTaxonomyTermType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $json = json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $this->assertIsString($json);
        $this->assertNotEmpty($json);

        // Decode back and verify round-trip.
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('3.1.0', $decoded['openapi']);
    }

    #[Test]
    public function addingNewEntityTypeUpdatesSpec(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec1 = $generator->generate();
        $pathCount1 = count($spec1['paths']);
        $schemaCount1 = count($spec1['components']['schemas']);

        // Add a second entity type.
        $this->registerTaxonomyTermType();
        $generator2 = new OpenApiGenerator($this->entityTypeManager);

        $spec2 = $generator2->generate();
        $pathCount2 = count($spec2['paths']);
        $schemaCount2 = count($spec2['components']['schemas']);

        // Should have more paths and schemas.
        $this->assertGreaterThan($pathCount1, $pathCount2);
        $this->assertGreaterThan($schemaCount1, $schemaCount2);

        // Verify the new type's paths exist.
        $this->assertArrayHasKey('/api/taxonomy_term', $spec2['paths']);
        $this->assertArrayHasKey('/api/taxonomy_term/{id}', $spec2['paths']);
    }

    #[Test]
    public function emptyEntityTypeManagerGeneratesMinimalSpec(): void
    {
        $generator = new OpenApiGenerator($this->entityTypeManager);
        $spec = $generator->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('components', $spec);

        // Shared schemas still present even with no entity types.
        $this->assertArrayHasKey('schemas', $spec['components']);
        $this->assertArrayHasKey('JsonApiDocument', $spec['components']['schemas']);
    }

    #[Test]
    public function resourceSchemaHasCorrectStructure(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $nodeResource = $spec['components']['schemas']['NodeResource'];

        $this->assertSame('object', $nodeResource['type']);
        $this->assertContains('type', $nodeResource['required']);
        $this->assertContains('id', $nodeResource['required']);
        $this->assertArrayHasKey('type', $nodeResource['properties']);
        $this->assertArrayHasKey('id', $nodeResource['properties']);
        $this->assertArrayHasKey('attributes', $nodeResource['properties']);
    }

    #[Test]
    public function createRequestSchemaRequiresDataAndType(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $createRequest = $spec['components']['schemas']['NodeCreateRequest'];

        $this->assertContains('data', $createRequest['required']);
        $this->assertContains('type', $createRequest['properties']['data']['required']);
        $this->assertContains('attributes', $createRequest['properties']['data']['required']);
    }

    #[Test]
    public function updateRequestSchemaRequiresId(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();
        $updateRequest = $spec['components']['schemas']['NodeUpdateRequest'];

        $this->assertContains('data', $updateRequest['required']);
        $this->assertContains('type', $updateRequest['properties']['data']['required']);
        $this->assertContains('id', $updateRequest['properties']['data']['required']);
    }

    #[Test]
    public function customBasePath(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator(
            $this->entityTypeManager,
            basePath: '/v2',
        );

        $spec = $generator->generate();

        $this->assertArrayHasKey('/v2/node', $spec['paths']);
        $this->assertArrayHasKey('/v2/node/{id}', $spec['paths']);
    }

    #[Test]
    public function operationsHaveCorrectSummaries(): void
    {
        $this->registerNodeType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        $this->assertStringContainsString('Node', $spec['paths']['/api/node']['get']['summary']);
        $this->assertStringContainsString('Node', $spec['paths']['/api/node']['post']['summary']);
        $this->assertStringContainsString('Node', $spec['paths']['/api/node/{id}']['get']['summary']);
        $this->assertStringContainsString('Node', $spec['paths']['/api/node/{id}']['patch']['summary']);
        $this->assertStringContainsString('Node', $spec['paths']['/api/node/{id}']['delete']['summary']);
    }

    #[Test]
    public function taxonomyTermPathsHaveCorrectSchemaReferences(): void
    {
        $this->registerTaxonomyTermType();
        $generator = new OpenApiGenerator($this->entityTypeManager);

        $spec = $generator->generate();

        // POST request body should reference TaxonomyTermCreateRequest.
        $postRequestSchema = $spec['paths']['/api/taxonomy_term']['post']['requestBody']['content']['application/vnd.api+json']['schema'];
        $this->assertStringContainsString('TaxonomyTermCreateRequest', $postRequestSchema['$ref']);

        // PATCH request body should reference TaxonomyTermUpdateRequest.
        $patchRequestSchema = $spec['paths']['/api/taxonomy_term/{id}']['patch']['requestBody']['content']['application/vnd.api+json']['schema'];
        $this->assertStringContainsString('TaxonomyTermUpdateRequest', $patchRequestSchema['$ref']);
    }
}
