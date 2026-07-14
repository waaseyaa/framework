<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
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
     * A custom update/delete resolver replaces the generated resolver and
     * therefore MUST preserve its existence-oracle contract: access-denied and
     * absent targets have the same caller-visible error. Prefer delegating to
     * EntityResolver so entity- and field-level denials stay collapsed.
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

        // GraphQL-over-HTTP: GET is query-only. A mutation reached over GET
        // (`GET /graphql?query=mutation{...}`) is a CSRF vector — a cross-site
        // GET carries the victim's session cookie with no preflight. Reject the
        // mutation before it executes; mutations must use POST.
        if ($method === 'GET' && $this->selectsMutation($query, $operationName)) {
            return [
                'statusCode' => 405,
                'body' => ['errors' => [['message' => 'Mutations are not allowed over GET; use POST.']]],
            ];
        }

        // R11 (audit A9): `/graphql` is registered allowAll() (anonymous-reachable)
        // and, unlike this class's REST sibling (PATCH/DELETE gated by
        // requireAuthentication()), had no operation-type-aware auth gate of its
        // own -- every generated update{Type}/delete{Type} mutation executed for
        // the anonymous account and then relied on the entity-level access policy
        // to deny it. Because the resolver's "entity absent" and "access denied"
        // errors were textually distinct, an anonymous caller could enumerate the
        // existence of any entity id by diffing the two error messages, even when
        // every per-entity access policy was itself correct. Reject BEFORE any
        // resolver runs, for every HTTP method, not just GET, so an anonymous
        // caller never reaches entity-loading code at all: no distinguishable
        // error, no timing side channel. The rejection is intentionally UNIFORM
        // (no entity id/type in the message) and identical for every mutation
        // shape (aliases, named operations, create/update/delete) because
        // selectsMutation() gates at the operation-type level, not per-field.
        // `parseRequest()` accepts only a single `{query, variables, operationName}`
        // object (`is_array($data)` on a JSON *list* body finds no 'query' key and
        // falls through to the "Missing query" 400) -- this endpoint does not
        // support GraphQL request batching, so there is no batched-array path that
        // could smuggle a mutation past this single-document check.
        if (!$this->account->isAuthenticated() && $this->selectsMutation($query, $operationName)) {
            return [
                'statusCode' => 401,
                'body' => ['errors' => [['message' => 'Authentication required for mutation operations.']]],
            ];
        }

        $guard = new GraphQlAccessGuard($this->accessHandler, $this->account);
        // New ReferenceLoader per request — buffers entity_reference IDs for DataLoader-style batching.
        $referenceLoader = new ReferenceLoader(
            $this->entityTypeManager,
            $guard,
            $this->maxDepth,
        );
        $entityResolver = new EntityResolver($this->entityTypeManager, $guard, $this->account);

        // R12 (audit A10, SECURITY): the per-request, account-bound
        // collaborators (entityResolver, referenceLoader) travel as the
        // GraphQL execution contextValue below, NOT as SchemaFactory
        // constructor args. SchemaFactory::build() may return a Schema
        // CACHED from an earlier request/account, so anything captured by
        // its resolver closures would leak across requests under
        // worker-mode process reuse. See GraphQlExecutionContext.
        $executionContext = new GraphQlExecutionContext($entityResolver, $referenceLoader);

        $schemaFactory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
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
                contextValue: $executionContext,
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
     * Whether the operation that would execute for this request is a mutation.
     *
     * Used to enforce the GraphQL-over-HTTP rule that GET is query-only. An
     * unparseable query selects no executable operation, so the normal
     * execution path is left to surface the syntax error.
     */
    private function selectsMutation(string $query, ?string $operationName): bool
    {
        try {
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (SyntaxError) {
            return false;
        }

        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode || $definition->operation !== 'mutation') {
                continue;
            }

            // With an explicit operationName only the named operation executes.
            if ($operationName !== null && $operationName !== '') {
                if (($definition->name->value ?? null) === $operationName) {
                    return true;
                }
                continue;
            }

            // No operationName: a lone mutation would execute; conservatively
            // reject any mutation operation present in the document.
            return true;
        }

        return false;
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
