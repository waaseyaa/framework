<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Content\ContentToolSet;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\AuthenticatedMcpEndpoint;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\ReadOnlyToolRegistry;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SymfonyTestSanitizer;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;

/**
 * Production-shaped regression for #2145: the real Content Publishing tool
 * set (`ContentToolSet` over a real revisionable SQLite entity type) served
 * through the real authenticated MCP write tier
 * (`AuthenticatedMcpEndpoint` → `McpEndpoint` → `CapabilityScopedToolRegistry`
 * → `AgentToolRegistryBridge`), driven by real JSON-RPC request bodies.
 *
 * The reported defect: `article.rollback` called WITHOUT the required
 * `target_revision_id` reached `ContentToolSet`'s handler, raised
 * `Undefined array key`, and only failed safely because of an incidental
 * downstream guard. After this change the declared JSON Schema is enforced
 * before dispatch, so the agent receives a `VALIDATION_FAILED` envelope and
 * the handler never runs.
 *
 * Both MCP tiers are exercised: the unauthenticated public `/mcp` surface
 * (which must still 200 with anonymous read semantics and validate its own
 * read tools) and the authenticated `/mcp/write` tier.
 */
#[CoversNothing]
final class McpToolsCallSchemaEnforcementTest extends TestCase
{
    private const string TOKEN = 'write-tier-token';
    private const string CAPABILITY = 'publish test articles';

    /** @var array<string, AgentTool> */
    private array $tools = [];

    /** @var array<string, int> Handler invocations per tool name, counted around the real handler. */
    private array $handlerCalls = [];
    private AuthenticatedMcpEndpoint $writeTier;
    private ContentPublisher $publisher;
    private PublisherAccount $actor;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite();
        $articleType = new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $schemaHandler = new SqlSchemaHandler($articleType, $db);
        $schemaHandler->ensureTable();
        $schemaHandler->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($db);
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $articleType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $articleType),
            $db,
        );

        $descriptor = new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true),
                'title' => new FieldSpec(type: 'string', required: true),
                'body_html' => new FieldSpec(type: 'text', html: true),
                // #2737: the two first-party shapes whose advertised constraints
                // use composition keywords (anyOf nullable, items.oneOf +
                // uniqueItems reference list).
                'publish_on' => new FieldSpec(type: 'date', nullable: true),
                'related' => new FieldSpec(type: 'reference_list', maxItems: 3),
            ],
            htmlSanitizer: new SymfonyTestSanitizer(['p']),
            validators: [],
            publishCapability: self::CAPABILITY,
        );
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::publishing($db);
        $this->publisher = new ContentPublisher($descriptor, $repo, new IdempotencyStore($db));
        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);

        $set = new ContentToolSet(
            $this->publisher,
            $descriptor,
            new PreviewLinkService('preview-secret'),
            static fn(string $id, int $exp, string $sig): string => "/news/preview/$id?exp=$exp&sig=$sig",
        );

        $registry = $this->collectingRegistry();
        $set->register($registry, 'article');

        $this->writeTier = new AuthenticatedMcpEndpoint(new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $this->actor]),
            agentRegistry: new CapabilityScopedToolRegistry($registry, [self::CAPABILITY]),
        ));
    }

    private function collectingRegistry(): ToolRegistryInterface
    {
        return new class ($this->tools, $this->handlerCalls) implements ToolRegistryInterface {
            /**
             * @param array<string, AgentTool> $sink
             * @param array<string, int> $calls
             */
            public function __construct(private array &$sink, private array &$calls) {}

            public function register(AgentTool $tool): void
            {
                // Forwarding observer: counts handler entries without
                // substituting the handler, so "zero handler calls" is a
                // measured fact rather than an inference from the envelope.
                $calls = &$this->calls;
                $counting = new class ($tool->impl, $tool->name, $calls) implements AgentToolInterface {
                    /** @param array<string, int> $calls */
                    public function __construct(private AgentToolInterface $inner, private string $name, private array &$calls) {}

                    public function execute(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
                    {
                        $this->calls[$this->name] = ($this->calls[$this->name] ?? 0) + 1;

                        return $this->inner->execute($arguments, $account);
                    }

                    public function dryRun(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
                    {
                        return $this->inner->dryRun($arguments, $account);
                    }

                    public function argumentsForAudit(array $arguments): array
                    {
                        return $this->inner->argumentsForAudit($arguments);
                    }

                    public function inputSchema(): array
                    {
                        return $this->inner->inputSchema();
                    }

                    public function description(): string
                    {
                        return $this->inner->description();
                    }
                };
                $this->sink[$tool->name] = new AgentTool(
                    name: $tool->name,
                    capability: $tool->capability,
                    destructive: $tool->destructive,
                    dryRunSupported: $tool->dryRunSupported,
                    category: $tool->category,
                    inputSchema: $tool->inputSchema,
                    impl: $counting,
                    title: $tool->title,
                    outputSchema: $tool->outputSchema,
                    idempotent: $tool->idempotent,
                    openWorld: $tool->openWorld,
                );
            }

            public function get(string $name): AgentTool
            {
                return $this->sink[$name] ?? throw new ToolNotFoundException($name);
            }

            public function has(string $name): bool
            {
                return isset($this->sink[$name]);
            }

            public function all(): iterable
            {
                return $this->sink;
            }
        };
    }

    /** @return array<string, mixed> The decoded JSON-RPC response. */
    private function rpc(string $toolName, mixed $arguments, ?string $token = self::TOKEN): array
    {
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => $toolName, 'arguments' => $arguments],
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);

        $server = $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : [];
        $server += [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], $server, $body);
        $response = $this->writeTier->serve($this->actor, $request);

        return [
            'status' => $response->getStatusCode(),
            'json' => \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
            'raw' => (string) $response->getContent(),
        ];
    }

    /** @return array<string, mixed> Decoded tool payload from a successful call. */
    private function successPayload(array $rpc): array
    {
        self::assertSame(200, $rpc['status']);
        self::assertArrayHasKey('result', $rpc['json'], 'Expected a JSON-RPC result: ' . $rpc['raw']);
        self::assertNotTrue($rpc['json']['result']['isError'] ?? false, 'Unexpected tool error: ' . $rpc['raw']);

        return \json_decode((string) $rpc['json']['result']['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> Decoded structured error envelope. */
    private function errorEnvelope(array $rpc): array
    {
        self::assertSame(200, $rpc['status']);
        self::assertTrue($rpc['json']['result']['isError'] ?? false, 'Expected isError: ' . $rpc['raw']);

        return \json_decode((string) $rpc['json']['result']['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
    }

    /** Create + publish a draft, returning [id, revision_id]. */
    private function publishedArticle(string $slug): array
    {
        $draft = $this->successPayload($this->rpc('article.createDraft', [
            'values' => ['slug' => $slug, 'title' => 'Original title', 'body_html' => '<p>Original</p>'],
            'idempotency_key' => 'create-' . $slug,
        ]));
        $published = $this->successPayload($this->rpc('article.publish', [
            'id' => (string) $draft['id'],
            'expected_revision_id' => $draft['revision_id'],
            'idempotency_key' => 'publish-' . $slug,
        ]));

        return [(string) $published['id'], (int) $published['revision_id']];
    }

    #[Test]
    public function the_reported_rollback_call_without_target_revision_id_is_rejected_before_dispatch(): void
    {
        [$id, $revisionId] = $this->publishedArticle('rollback-regression');

        // The exact #2145 payload: `article.rollback` missing `target_revision_id`.
        $envelope = $this->errorEnvelope($this->rpc('article.rollback', [
            'id' => $id,
            'idempotency_key' => 'rollback-missing-arg',
        ]));

        self::assertSame('VALIDATION_FAILED', $envelope['code']);
        self::assertSame(
            ['target_revision_id'],
            array_map(static fn(array $e): string => $e['field'], $envelope['errors']),
        );

        // Not the incidental downstream guard: the old behaviour surfaced
        // "Revision 0 does not exist" from the publisher.
        self::assertStringNotContainsString('Revision 0', $envelope['message']);

        // No PHP warning text leaked into the transport (the cli-server
        // `Undefined array key` HTML prefix the issue observed).
        self::assertStringStartsWith('{', trim($this->rpc('article.rollback', [
            'id' => $id,
            'idempotency_key' => 'rollback-missing-arg-2',
        ])['raw']));

        // And the content is untouched: still the published revision.
        $current = $this->successPayload($this->rpc('article.get', ['id' => $id]));
        self::assertSame($revisionId, $current['revision_id']);
        self::assertSame('Original title', $current['title']);
    }

    #[Test]
    public function wrong_types_invalid_enums_and_additional_properties_are_all_rejected(): void
    {
        [$id, $revisionId] = $this->publishedArticle('type-checks');

        // Wrong scalar type on a declared integer.
        $wrongType = $this->errorEnvelope($this->rpc('article.rollback', [
            'id' => $id,
            'target_revision_id' => 'first',
            'idempotency_key' => 'rollback-wrong-type',
        ]));
        self::assertSame('VALIDATION_FAILED', $wrongType['code']);
        self::assertSame('target_revision_id', $wrongType['errors'][0]['field']);

        // additionalProperties: false — an unknown argument is refused, not ignored.
        $extra = $this->errorEnvelope($this->rpc('article.publish', [
            'id' => $id,
            'expected_revision_id' => $revisionId,
            'idempotency_key' => 'publish-extra-arg',
            'sneaky_status_override' => true,
        ]));
        self::assertSame('VALIDATION_FAILED', $extra['code']);
        self::assertSame('sneaky_status_override', $extra['errors'][0]['field']);

        // Nested object: the writable-values payload is validated too.
        $nested = $this->errorEnvelope($this->rpc('article.createDraft', [
            'values' => ['slug' => 'nested', 'title' => 42],
            'idempotency_key' => 'create-nested-bad',
        ]));
        self::assertSame('VALIDATION_FAILED', $nested['code']);
        self::assertSame('values.title', $nested['errors'][0]['field']);

        // Bounds declared in the schema (idempotency_key minLength: 8).
        $short = $this->errorEnvelope($this->rpc('article.get', ['id' => '']));
        self::assertSame('VALIDATION_FAILED', $short['code']);
        self::assertSame('id', $short['errors'][0]['field']);
    }

    #[Test]
    public function content_publishings_own_field_level_validation_still_runs(): void
    {
        // Schema-VALID input that violates the publishing layer's own rules
        // must still produce publishing's VALIDATION_FAILED with its field
        // errors — schema enforcement must not short-circuit or replace it.
        $envelope = $this->errorEnvelope($this->rpc('article.createDraft', [
            'values' => ['title' => 'No slug supplied'],
            'idempotency_key' => 'create-missing-required-field',
        ]));

        self::assertSame('VALIDATION_FAILED', $envelope['code']);
        self::assertSame(
            ['slug'],
            array_map(static fn(array $e): string => $e['field'], $envelope['errors']),
            'Publishing owns required-field semantics for the writable payload.',
        );
    }

    #[Test]
    public function the_full_editorial_lifecycle_still_works_end_to_end(): void
    {
        $draft = $this->successPayload($this->rpc('article.createDraft', [
            'values' => ['slug' => 'lifecycle', 'title' => 'V1', 'body_html' => '<p>One</p>'],
            'idempotency_key' => 'lifecycle-create',
        ]));
        self::assertFalse($draft['status']);

        $updated = $this->successPayload($this->rpc('article.updateDraft', [
            'id' => (string) $draft['id'],
            'values' => ['title' => 'V2'],
            'expected_revision_id' => $draft['revision_id'],
            'idempotency_key' => 'lifecycle-update',
        ]));
        self::assertSame('V2', $updated['title']);

        $preview = $this->successPayload($this->rpc('article.preview', ['id' => (string) $draft['id']]));
        self::assertStringContainsString('/news/preview/', (string) $preview['preview_url']);

        $published = $this->successPayload($this->rpc('article.publish', [
            'id' => (string) $draft['id'],
            'expected_revision_id' => $updated['revision_id'],
            'idempotency_key' => 'lifecycle-publish',
            'note' => 'Ship it',
        ]));
        self::assertTrue($published['status']);

        $revisions = $this->successPayload($this->rpc('article.revisions', ['id' => (string) $draft['id']]));
        self::assertGreaterThanOrEqual(2, count($revisions['revisions']));

        $rolledBack = $this->successPayload($this->rpc('article.rollback', [
            'id' => (string) $draft['id'],
            'target_revision_id' => (int) $draft['revision_id'],
            'idempotency_key' => 'lifecycle-rollback',
            'note' => 'Back to V1',
        ]));
        self::assertSame('V1', $rolledBack['title']);

        $unpublished = $this->successPayload($this->rpc('article.unpublish', [
            'id' => (string) $draft['id'],
            'expected_revision_id' => $rolledBack['revision_id'],
            'idempotency_key' => 'lifecycle-unpublish',
        ]));
        self::assertFalse($unpublished['status']);
    }

    #[Test]
    public function valid_idempotent_replays_still_return_the_original_result(): void
    {
        $first = $this->successPayload($this->rpc('article.createDraft', [
            'values' => ['slug' => 'replay', 'title' => 'Replay me', 'body_html' => '<p>R</p>'],
            'idempotency_key' => 'replay-key-stable',
        ]));

        // Byte-identical replay of a schema-valid mutation.
        $replay = $this->successPayload($this->rpc('article.createDraft', [
            'values' => ['slug' => 'replay', 'title' => 'Replay me', 'body_html' => '<p>R</p>'],
            'idempotency_key' => 'replay-key-stable',
        ]));

        self::assertSame($first['id'], $replay['id'], 'A replay must not create a second entity.');
        self::assertSame($first['revision_id'], $replay['revision_id']);

        // A publish replay is likewise stable.
        $published = $this->successPayload($this->rpc('article.publish', [
            'id' => (string) $first['id'],
            'expected_revision_id' => $first['revision_id'],
            'idempotency_key' => 'replay-publish-stable',
        ]));
        $publishedAgain = $this->successPayload($this->rpc('article.publish', [
            'id' => (string) $first['id'],
            'expected_revision_id' => $first['revision_id'],
            'idempotency_key' => 'replay-publish-stable',
        ]));
        self::assertSame($published['revision_id'], $publishedAgain['revision_id']);
    }

    #[Test]
    public function the_write_tier_still_fails_closed_without_a_token(): void
    {
        // Auth ordering is preserved: a schema-invalid payload from an
        // unauthenticated caller yields 401, never a validation envelope.
        $rpc = $this->rpc('article.rollback', ['id' => 'x'], token: null);

        self::assertSame(401, $rpc['status']);
        self::assertSame(-32001, $rpc['json']['error']['code']);
        self::assertStringNotContainsString('VALIDATION_FAILED', $rpc['raw']);
    }

    #[Test]
    public function an_invalid_token_yields_401_not_a_validation_envelope(): void
    {
        $rpc = $this->rpc('article.rollback', ['id' => 'x'], token: 'wrong-token');

        self::assertSame(401, $rpc['status']);
        self::assertSame(-32001, $rpc['json']['error']['code']);
        self::assertStringNotContainsString('VALIDATION_FAILED', $rpc['raw']);
    }

    #[Test]
    public function authorization_is_unweakened_for_schema_valid_input(): void
    {
        // Same tier and tool, but the bearer account holds no publish
        // capability. Schema-valid input must still be refused on
        // authorization grounds — validation replaces nothing.
        $powerless = new PublisherAccount(uid: 900002, permissions: []);
        $tier = new AuthenticatedMcpEndpoint(new McpEndpoint(
            auth: new BearerTokenAuth(['powerless-token' => $powerless]),
            agentRegistry: new CapabilityScopedToolRegistry($this->collectingRegistry(), [self::CAPABILITY]),
        ));

        $call = static function (array $arguments) use ($tier, $powerless): array {
            $body = \json_encode([
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => 'article.createDraft', 'arguments' => $arguments],
                'id' => 1,
            ], \JSON_THROW_ON_ERROR);
            $response = $tier->serve($powerless, HttpRequest::create(
                '/mcp/write',
                'POST',
                [],
                [],
                [],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer powerless-token',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json, text/event-stream',
                ],
                $body,
            ));
            $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            return \json_decode((string) $decoded['result']['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
        };

        // Schema-valid but unauthorized → the authorization outcome, not VALIDATION_FAILED.
        self::assertSame('UNAUTHORIZED', $call([
            'values' => ['slug' => 'nope', 'title' => 'Nope'],
            'idempotency_key' => 'unauthorized-valid',
        ])['code']);

        // Schema-invalid and unauthorized → validation reports first, and
        // discloses only the schema this tier already publishes via tools/list.
        self::assertSame('VALIDATION_FAILED', $call(['values' => ['slug' => 'nope']])['code']);
    }

    #[Test]
    public function the_unauthenticated_public_read_surface_validates_its_own_tools(): void
    {
        // The public read-only tier: anonymous auth, no 401, destructive
        // content tools structurally absent — and its own read tools are
        // schema-enforced identically.
        $publicRegistry = $this->collectingRegistry();
        $public = new McpEndpoint(
            auth: new PublicAnonymousAuth(),
            agentRegistry: new ReadOnlyToolRegistry($publicRegistry, PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES),
        );

        $body = \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'article.rollback', 'arguments' => ['id' => 'x']],
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);
        $response = $public->handle($this->actor, HttpRequest::create('/mcp', 'POST', [], [], [], [], $body));
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        // Never 401 on the public surface; the destructive tool is simply unknown.
        self::assertSame(200, $response->statusCode);
        self::assertSame(-32602, $decoded['error']['code']);
        self::assertStringContainsString('Unknown tool', $decoded['error']['message']);
    }

    #[Test]
    public function every_registered_content_tool_declares_an_enforceable_schema(): void
    {
        self::assertNotEmpty($this->tools);
        foreach ($this->tools as $name => $tool) {
            self::assertSame(
                'https://json-schema.org/draft/2020-12/schema',
                $tool->inputSchema['$schema'] ?? null,
                "$name must declare its JSON Schema dialect.",
            );
            self::assertSame('object', $tool->inputSchema['type'] ?? null, "$name must declare an object input.");
            self::assertFalse($tool->inputSchema['additionalProperties'] ?? true, "$name must be closed.");
        }
    }

    #[Test]
    public function advertised_nullable_and_reference_list_constraints_refuse_before_dispatch(): void
    {
        // #2737: `tools/list` advertises anyOf (nullable), items.oneOf
        // (reference alternatives) and uniqueItems for these fields. Each
        // malformed family must be an input-validation refusal with ZERO
        // handler calls — not a domain VALIDATION_FAILED that happened to
        // fail safely after the handler ran.
        $malformed = [
            'values.publish_on' => ['publish_on' => false],
            'values.related.0' => ['related' => [0]],
            'values.related.0 (empty string)' => ['related' => ['']],
            'values.related.0 (boolean)' => ['related' => [false]],
            'values.related.1' => ['related' => ['dup', 'dup']],
            'values.related' => ['related' => [1, 2, 3, 4]],
        ];
        $seq = 0;
        foreach ($malformed as $expectedField => $values) {
            $envelope = $this->errorEnvelope($this->rpc('article.createDraft', [
                'values' => ['slug' => 'union-' . $seq, 'title' => 'Union'] + $values,
                'idempotency_key' => 'union-malformed-' . $seq++,
            ]));
            $expectedField = explode(' ', $expectedField)[0];

            self::assertSame('VALIDATION_FAILED', $envelope['code'], $expectedField);
            self::assertSame(
                [$expectedField],
                array_map(static fn(array $e): string => $e['field'], $envelope['errors']),
                $expectedField,
            );
        }
        self::assertSame([], $this->handlerCalls, 'Schema-invalid input must never enter a handler.');

        // Nothing was persisted for any of the refused calls.
        $listing = $this->successPayload($this->rpc('article.list', []));
        self::assertSame([], $listing['items']);

        // Positive controls: a valid null and a valid reference list are
        // admitted, reach the handler, and persist a draft (sql-blob layout —
        // the sql-column layout has no array column encoding and refuses
        // structured values, which is a storage limitation, not admission).
        $draft = $this->successPayload($this->rpc('article.createDraft', [
            'values' => ['slug' => 'union-valid', 'title' => 'Union', 'publish_on' => null, 'related' => [1, 'b']],
            'idempotency_key' => 'union-valid-key',
        ]));
        self::assertSame(1, $this->handlerCalls['article.createDraft'] ?? 0);
        self::assertSame([1, 'b'], $draft['related']);
        $persisted = $this->successPayload($this->rpc('article.get', ['id' => (string) $draft['id']]));
        self::assertSame([1, 'b'], $persisted['related']);
    }

}
