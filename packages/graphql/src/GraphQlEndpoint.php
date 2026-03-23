<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * HTTP boundary for the GraphQL endpoint.
 *
 * Parses GET/POST requests, builds schema from entity types,
 * executes via webonyx/graphql-php, and returns the result array.
 */
final class GraphQlEndpoint
{
    /** @var array<string, array{args?: array<string, mixed>, resolve?: callable}> */
    private array $mutationOverrides = [];
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountInterface $account,
        private readonly int $maxDepth = 3,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register mutation overrides (extra args, custom resolvers).
     *
     * @param array<string, array{args?: array<string, mixed>, resolve?: callable}> $overrides
     */
    public function withMutationOverrides(array $overrides): self
    {
        $clone = clone $this;
        $clone->mutationOverrides = array_merge($clone->mutationOverrides, $overrides);
        return $clone;
    }

    /**
     * @param array<string, string> $queryParams $_GET params for GET requests
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $body, array $queryParams = []): array
    {
        try {
            $input = $this->parseRequest($method, $body, $queryParams);
        } catch (\JsonException $e) {
            return [
                'statusCode' => 400,
                'body' => ['errors' => [['message' => 'Invalid JSON: ' . $e->getMessage()]]],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'statusCode' => 400,
                'body' => ['errors' => [['message' => $e->getMessage()]]],
            ];
        }

        $query = $input['query'];
        $variables = $input['variables'];
        $operationName = $input['operationName'];

        if ($query === '') {
            return [
                'statusCode' => 400,
                'body' => ['errors' => [['message' => 'Missing query']]],
            ];
        }

        $guard = new GraphQlAccessGuard($this->accessHandler, $this->account);
        $referenceLoader = new ReferenceLoader(
            $this->entityTypeManager,
            $guard,
            $this->maxDepth,
        );
        $entityResolver = new EntityResolver($this->entityTypeManager, $guard);

        $schemaFactory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            entityResolver: $entityResolver,
            referenceLoader: $referenceLoader,
        );

        if ($this->mutationOverrides !== []) {
            $schemaFactory = $schemaFactory->withMutationOverrides($this->mutationOverrides);
        }

        $schema = $schemaFactory->build();

        // Disable introspection for anonymous users (id === 0).
        $validationRules = null;
        if ($this->account->id() === 0) {
            $validationRules = array_merge(
                DocumentValidator::defaultRules(),
                [new DisableIntrospection(DisableIntrospection::ENABLED)],
            );
        }

        try {
            $result = GraphQL::executeQuery(
                schema: $schema,
                source: $query,
                variableValues: $variables,
                operationName: $operationName,
                validationRules: $validationRules,
            );

            return [
                'statusCode' => 200,
                'body' => $result->toArray(DebugFlag::NONE),
            ];
        } catch (\Exception $e) {
            $this->logger->error('GraphQL execution error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return [
                'statusCode' => 500,
                'body' => ['errors' => [['message' => 'Internal server error']]],
            ];
        }
    }

    /**
     * @return array{query: string, variables: ?array<string, mixed>, operationName: ?string}
     */
    private function parseRequest(string $method, string $body, array $queryParams): array
    {
        if ($method === 'GET') {
            $variables = null;
            if (isset($queryParams['variables']) && $queryParams['variables'] !== '') {
                $decoded = json_decode($queryParams['variables'], true, 512, JSON_THROW_ON_ERROR);
                $variables = is_array($decoded) ? $decoded : null;
            }

            return [
                'query' => is_string($queryParams['query'] ?? null) ? $queryParams['query'] : '',
                'variables' => $variables,
                'operationName' => is_string($queryParams['operationName'] ?? null) ? $queryParams['operationName'] : null,
            ];
        }

        if ($body === '') {
            throw new \InvalidArgumentException('Empty request body');
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Request body must be a JSON object');
        }

        $variables = null;
        if (isset($data['variables']) && is_array($data['variables'])) {
            $variables = $data['variables'];
        }

        return [
            'query' => is_string($data['query'] ?? null) ? $data['query'] : '',
            'variables' => $variables,
            'operationName' => is_string($data['operationName'] ?? null) ? $data['operationName'] : null,
        ];
    }
}
