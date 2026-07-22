<?php

declare(strict_types=1);

namespace Waaseyaa\Api\OpenApi;

use Waaseyaa\Api\EntityTypeApiExposure;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Generates a complete OpenAPI 3.1 specification from EntityTypeManager definitions.
 *
 * Every registered entity type becomes a set of CRUD endpoints with proper
 * request/response schemas following the JSON:API specification.
 */
final class OpenApiGenerator
{
    private readonly SchemaBuilder $schemaBuilder;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private string $basePath = '/api',
        private string $title = 'Waaseyaa API',
        private string $version = '0.1.0',
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {
        $this->schemaBuilder = new SchemaBuilder();
    }

    /**
     * Generate the full OpenAPI 3.1 spec as an array.
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => \sprintf(
                    'Auto-generated JSON:API specification for %s.',
                    $this->title,
                ),
            ],
            'paths' => new \stdClass(),
            'components' => [
                'schemas' => $this->buildSharedSchemas(),
            ],
        ];

        $definitions = $this->entityTypeManager->getDefinitions();

        if ($definitions !== []) {
            $paths = [];
            $schemas = $spec['components']['schemas'];

            foreach ($definitions as $entityTypeId => $entityType) {
                if (!EntityTypeApiExposure::isExposed($entityType, $this->exposurePolicy)) {
                    continue;
                }
                $paths = array_merge($paths, $this->buildPathsForEntityType($entityType));
                $schemas = array_merge($schemas, $this->buildSchemasForEntityType($entityType));
            }

            if ($paths !== []) {
                $spec['paths'] = $paths;
            }
            $spec['components']['schemas'] = $schemas;
        }

        return $spec;
    }

    /**
     * Build the five CRUD path operations for a single entity type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPathsForEntityType(EntityTypeInterface $entityType): array
    {
        $entityTypeId = $entityType->id();
        $schemaName = $this->schemaBuilder->toSchemaName($entityTypeId);
        $label = $entityType->getLabel();
        $collectionPath = $this->basePath . '/' . $entityTypeId;
        $resourcePath = $collectionPath . '/{id}';

        $paths = [];

        // Collection path: GET (list) and POST (create).
        $paths[$collectionPath] = [
            'get' => [
                'summary' => \sprintf('List all %s entities', $label),
                'operationId' => \sprintf('list%s', $schemaName),
                'tags' => [$label],
                'responses' => [
                    '200' => [
                        'description' => \sprintf('A collection of %s resources.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/' . $schemaName . 'Resource',
                                            ],
                                        ],
                                        'links' => [
                                            '$ref' => '#/components/schemas/JsonApiLinks',
                                        ],
                                        'jsonapi' => [
                                            '$ref' => '#/components/schemas/JsonApiVersion',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'post' => [
                'summary' => \sprintf('Create a new %s entity', $label),
                'operationId' => \sprintf('create%s', $schemaName),
                'tags' => [$label],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/vnd.api+json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $schemaName . 'CreateRequest',
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '201' => [
                        'description' => \sprintf('The created %s resource.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiDocument',
                                ],
                            ],
                        ],
                    ],
                    '422' => [
                        'description' => 'Validation error.',
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiErrorDocument',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Resource path: GET (show), PATCH (update), DELETE (destroy).
        $paths[$resourcePath] = [
            'get' => [
                'summary' => \sprintf('Get a single %s entity', $label),
                'operationId' => \sprintf('get%s', $schemaName),
                'tags' => [$label],
                'parameters' => [
                    $this->buildIdParameter($label),
                ],
                'responses' => [
                    '200' => [
                        'description' => \sprintf('The requested %s resource.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiDocument',
                                ],
                            ],
                        ],
                    ],
                    '404' => [
                        'description' => \sprintf('%s not found.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiErrorDocument',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'patch' => [
                'summary' => \sprintf('Update an existing %s entity', $label),
                'operationId' => \sprintf('update%s', $schemaName),
                'tags' => [$label],
                'parameters' => [
                    $this->buildIdParameter($label),
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/vnd.api+json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . $schemaName . 'UpdateRequest',
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => \sprintf('The updated %s resource.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiDocument',
                                ],
                            ],
                        ],
                    ],
                    '404' => [
                        'description' => \sprintf('%s not found.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiErrorDocument',
                                ],
                            ],
                        ],
                    ],
                    '422' => [
                        'description' => 'Validation error.',
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiErrorDocument',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'delete' => [
                'summary' => \sprintf('Delete a %s entity', $label),
                'operationId' => \sprintf('delete%s', $schemaName),
                'tags' => [$label],
                'parameters' => [
                    $this->buildIdParameter($label),
                ],
                'responses' => [
                    '204' => [
                        'description' => \sprintf('%s successfully deleted.', $label),
                    ],
                    '404' => [
                        'description' => \sprintf('%s not found.', $label),
                        'content' => [
                            'application/vnd.api+json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/JsonApiErrorDocument',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $paths;
    }

    /**
     * Build component schemas for a single entity type.
     *
     * Generates four schemas per entity type:
     * - {EntityType}Resource — the JSON:API resource object
     * - {EntityType}Attributes — the attributes object
     * - {EntityType}CreateRequest — POST request body
     * - {EntityType}UpdateRequest — PATCH request body
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSchemasForEntityType(EntityTypeInterface $entityType): array
    {
        $schemaName = $this->schemaBuilder->toSchemaName($entityType->id());

        return [
            $schemaName . 'Resource' => $this->schemaBuilder->buildResourceSchema($entityType),
            $schemaName . 'Attributes' => $this->schemaBuilder->buildAttributesSchema($entityType),
            $schemaName . 'CreateRequest' => $this->schemaBuilder->buildCreateRequestSchema($entityType),
            $schemaName . 'UpdateRequest' => $this->schemaBuilder->buildUpdateRequestSchema($entityType),
        ];
    }

    /**
     * Build shared JSON:API schemas used across all entity types.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSharedSchemas(): array
    {
        return [
            'JsonApiDocument' => [
                'type' => 'object',
                'description' => 'A JSON:API document containing a single resource or a collection.',
                'properties' => [
                    'jsonapi' => [
                        '$ref' => '#/components/schemas/JsonApiVersion',
                    ],
                    'data' => [
                        'description' => 'The primary data of the document.',
                    ],
                    'meta' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                    'links' => [
                        '$ref' => '#/components/schemas/JsonApiLinks',
                    ],
                    'included' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                        ],
                    ],
                ],
            ],
            'JsonApiErrorDocument' => [
                'type' => 'object',
                'description' => 'A JSON:API error document.',
                'properties' => [
                    'jsonapi' => [
                        '$ref' => '#/components/schemas/JsonApiVersion',
                    ],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/components/schemas/JsonApiError',
                        ],
                    ],
                ],
            ],
            'JsonApiError' => [
                'type' => 'object',
                'description' => 'A JSON:API error object.',
                'required' => ['status', 'title'],
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'The HTTP status code as a string.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'A short, human-readable summary of the problem.',
                    ],
                    'detail' => [
                        'type' => 'string',
                        'description' => 'A detailed explanation specific to this occurrence.',
                    ],
                    'source' => [
                        'type' => 'object',
                        'description' => 'References to the primary source of the error.',
                        'properties' => [
                            'pointer' => [
                                'type' => 'string',
                            ],
                            'parameter' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
            'JsonApiVersion' => [
                'type' => 'object',
                'description' => 'JSON:API version object.',
                'properties' => [
                    'version' => [
                        'type' => 'string',
                        'example' => '1.1',
                    ],
                ],
            ],
            'JsonApiLinks' => [
                'type' => 'object',
                'description' => 'JSON:API links object.',
                'properties' => [
                    'self' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                    'next' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                    'prev' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build an {id} path parameter definition.
     *
     * @return array<string, mixed>
     */
    private function buildIdParameter(string $label): array
    {
        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => \sprintf('The unique identifier of the %s.', $label),
            'schema' => [
                'type' => 'string',
            ],
        ];
    }
}
