<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration\Audit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * F4 red-first: the MCP audit trail must be able to answer WHO attempted WHAT,
 * WHICH STAGE refused it, and WHETHER execution actually succeeded.
 *
 * Drives the real JSON-RPC boundary (`McpEndpoint::serve()`) against real audit
 * persistence (SQLite `audit_event` through the real `AuditEventWriter`), not
 * mocks — the defects live in the composition, so a mocked writer would miss
 * them.
 *
 * Every test here FAILS against the pre-F4 code. Each maps to one confirmed
 * defect from the assessment.
 */
#[CoversNothing]
final class McpAuditStagesTest extends TestCase
{
    private const string WRITE_CAP = 'content.publish';
    private const string TOKEN = 'write-tier-token';

    private DBALDatabase $db;
    private EventDispatcher $dispatcher;

    /** Set when the destructive fixture actually executed. */
    public static bool $executed = false;

    protected function setUp(): void
    {
        self::$executed = false;
        $this->db = DBALDatabase::createSqlite();
        AuthSchema::install($this->db);
        new AuditEventSchemaHandler($this->db)->ensureSchema();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new McpDispatchAuditListener(
            new AuditEventWriter(new AppendOnlyAuditDatabase($this->db)),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        $rows = [];
        $sql = 'SELECT event_kind, actor_uid, subject_uri, outcome, severity, attributes '
             . 'FROM audit_event ORDER BY id';
        foreach ($this->db->query($sql) as $row) {
            $r = (array) $row;
            $r['attributes'] = json_decode((string) $r['attributes'], true, 512, JSON_THROW_ON_ERROR);
            $rows[] = $r;
        }

        return $rows;
    }

    /** Every audit row's full serialized form — for "the secret is nowhere" assertions. */
    private function auditBytes(): string
    {
        return json_encode($this->auditRows(), JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ fixtures

    private function account(int $id, bool $hasCapability = true): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn($id > 0);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $p): bool => $hasCapability && $p === self::WRITE_CAP,
        );

        return $account;
    }

    /** A destructive write tool that records that it ran. */
    private function writeTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability(McpAuditStagesTest::WRITE_CAP_PUBLIC, $account);
                if ($denied !== null) {
                    return $denied;
                }
                McpAuditStagesTest::$executed = true;

                return AgentToolResult::success([['type' => 'text', 'text' => 'published']]);
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'api_key' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ];
            }

            public function description(): string
            {
                return 'Publishes content irreversibly.';
            }
        };

        return new AgentTool('article.publish', self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);
    }

    /** Readable from the anonymous fixture scope. */
    public const string WRITE_CAP_PUBLIC = self::WRITE_CAP;

    /** A tool whose execute() throws with a credential-bearing message. */
    private function throwingTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                throw new \RuntimeException('dsn=pgsql:host=10.9.9.9;password=sup3rs3cret');
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'explodes';
            }
        };

        return new AgentTool('article.boom', self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);
    }

    private function registry(AgentTool ...$tools): ToolRegistryInterface
    {
        $registry = new class implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            public array $map = [];

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->map);
            }
        };
        foreach ($tools as $t) {
            $registry->register($t);
        }

        return $registry;
    }

    private function endpoint(
        AccountInterface $account,
        ?ToolRegistryInterface $registry = null,
        int $rateLimitMax = 0,
        ?AtomicRateLimiterInterface $rateLimiter = null,
    ): McpEndpoint {
        $inner = $registry ?? $this->registry($this->writeTool());

        return new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $account]),
            agentRegistry: new CapabilityScopedToolRegistry($inner, [self::WRITE_CAP]),
            dispatcher: $this->dispatcher,
            rateLimiter: $rateLimiter ?? ($rateLimitMax > 0 ? new DatabaseRateLimiter($this->db) : null),
            rateLimitMaxRequests: $rateLimitMax,
            rateLimitWindowSeconds: 60,
            rateLimitTier: 'write',
        );
    }

    /** @param array<string, mixed> $params */
    private function call(McpEndpoint $endpoint, string $method, array $params = [], ?string $token = self::TOKEN): HttpResponse
    {
        $server = $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : [];
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        $server += [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], $server, $body);

        return $endpoint->serve($this->account(99), $request);
    }

    // ------------------------------------------------------- DEFECT: tool identity

    #[Test]
    public function the_audit_trail_records_which_tool_was_requested(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        $tools = array_column(array_column($this->auditRows(), 'attributes'), 'tool_name');

        self::assertContains(
            'article.publish',
            $tools,
            'The audit trail must name the tool. Pre-F4 it recorded only method="tools/call" + a params hash.',
        );
    }

    // ------------------------------------------------ DEFECT: outcome hardcoded allowed

    #[Test]
    public function a_capability_refusal_is_not_recorded_as_allowed(): void
    {
        // Principal authenticates but lacks the capability the tool requires.
        $this->call($this->endpoint($this->account(7, hasCapability: false)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        $rows = $this->auditRows();
        $byStage = array_column(array_column($rows, 'attributes'), 'stage');

        // `request_accepted` is legitimately `allowed` — the request WAS admitted.
        // What must never be `allowed` is the TERMINAL stage, which is the row
        // that states what actually happened.
        self::assertContains('authorization_refused', $byStage);
        self::assertNotContains(
            'execution_succeeded',
            $byStage,
            'A capability refusal must never be recorded as a successful execution.',
        );

        $terminal = array_values(array_filter(
            $rows,
            static fn(array $r): bool => ($r['attributes']['stage'] ?? null) === 'authorization_refused',
        ));
        self::assertSame('denied', $terminal[0]['outcome']);
        self::assertFalse(self::$executed, 'the capability guard must have refused before any side effect');
    }

    #[Test]
    public function a_thrown_tool_failure_is_recorded_as_a_failure(): void
    {
        $this->call(
            $this->endpoint($this->account(7), $this->registry($this->throwingTool())),
            'tools/call',
            ['name' => 'article.boom', 'arguments' => []],
        );

        $outcomes = array_column($this->auditRows(), 'outcome');

        self::assertContains('error', $outcomes, 'A thrown tool failure must be recorded as outcome=error.');
    }

    // ---------------------------------------------- DEFECT: audit fires before execution

    #[Test]
    public function a_post_execution_record_states_the_real_result(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        self::assertTrue(self::$executed, 'fixture sanity: the tool must have run');

        $stages = array_column(array_column($this->auditRows(), 'attributes'), 'stage');

        self::assertContains(
            'execution_succeeded',
            $stages,
            'A record written only BEFORE routing cannot state the result. A post-execution stage is required.',
        );
    }

    // ---------------------------------------------------- DEFECT: 401 is never audited

    #[Test]
    public function a_missing_bearer_token_is_audited(): void
    {
        $response = $this->call($this->endpoint($this->account(7)), 'tools/list', token: null);

        self::assertSame(401, $response->getStatusCode());
        self::assertNotEmpty(
            $this->auditRows(),
            'An authentication rejection must be audited — pre-F4 it returned before the event fired, '
            . 'making credential probing invisible.',
        );
    }

    #[Test]
    public function an_invalid_bearer_token_is_audited_without_recording_the_credential(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/list', token: 'wrong-token-abc123');

        self::assertNotEmpty($this->auditRows());
        self::assertStringNotContainsString(
            'wrong-token-abc123',
            $this->auditBytes(),
            'The supplied credential must never be stored.',
        );
    }

    // ---------------------------------------------------- DEFECT: 429 is never audited

    #[Test]
    public function a_rate_limited_request_is_audited(): void
    {
        $endpoint = $this->endpoint($this->account(7), rateLimitMax: 1);

        $this->call($endpoint, 'tools/list');            // consumes the budget
        $second = $this->call($endpoint, 'tools/list');  // refused

        self::assertSame(429, $second->getStatusCode());

        $stages = array_column(array_column($this->auditRows(), 'attributes'), 'stage');
        self::assertContains('rate_limited', $stages, 'A 429 decision must be audited.');
    }

    #[Test]
    public function a_rate_limiter_outage_is_audited_as_an_infrastructure_error(): void
    {
        $secret = 'sqlite://operator:sk-limiter-secret@audit';
        $broken = new class ($secret) implements AtomicRateLimiterInterface {
            public function __construct(private readonly string $secret) {}

            public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                throw new \RuntimeException($this->secret);
            }

            public function hit(string $key, int $decaySeconds): void {}

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return false;
            }

            public function attempts(string $key): int
            {
                return 0;
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                return $maxAttempts;
            }

            public function clear(string $key): void {}
        };

        $response = $this->call(
            $this->endpoint($this->account(7), rateLimitMax: 1, rateLimiter: $broken),
            'ping',
        );

        self::assertSame(503, $response->getStatusCode());
        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame('rate_limiter_unavailable', $rows[0]['attributes']['stage']);
        self::assertSame('error', $rows[0]['outcome']);
        self::assertSame('warning', $rows[0]['severity']);
        self::assertStringNotContainsString('sk-limiter-secret', $this->auditBytes());
    }

    #[Test]
    public function a_returned_protocol_error_is_audited_as_a_refusal(): void
    {
        $response = $this->call($this->endpoint($this->account(7)), 'initialize');

        self::assertSame(-32602, \json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['error']['code']);
        $rows = $this->auditRows();
        self::assertSame(
            ['request_accepted', 'invalid_params_refused'],
            array_column(array_column($rows, 'attributes'), 'stage'),
        );
        self::assertSame(['allowed', 'denied'], array_column($rows, 'outcome'));
        self::assertSame(-32602, $rows[1]['attributes']['metadata']['error_code']);
    }

    // ------------------------------------------------ DEFECT: only a params hash retained

    #[Test]
    public function safe_redacted_arguments_are_recorded_rather_than_an_opaque_hash(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1', 'api_key' => 'sk-should-be-redacted'],
        ]);

        $bytes = $this->auditBytes();

        // The identifying, non-sensitive argument survives...
        self::assertStringContainsString('a1', $bytes, 'Safe arguments must be recoverable from the audit trail.');
        // ...the credential-named one does not (AbstractAgentTool::argumentsForAudit redacts it).
        self::assertStringNotContainsString('sk-should-be-redacted', $bytes);
    }

    #[Test]
    public function an_unknown_tool_records_the_requested_name_and_a_refusal(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'no.such.tool',
            'arguments' => [],
        ]);

        $attrs = array_column($this->auditRows(), 'attributes');
        $stages = array_column($attrs, 'stage');
        $tools = array_column($attrs, 'tool_name');

        self::assertContains('tool_lookup_refused', $stages);
        self::assertContains('no.such.tool', $tools, 'The requested name is safe structural metadata.');
    }

    #[Test]
    public function a_schema_validation_refusal_is_recorded_as_its_own_stage(): void
    {
        // `id` is required by the fixture's schema.
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['api_key' => 'sk-nope'],
        ]);

        $stages = array_column(array_column($this->auditRows(), 'attributes'), 'stage');

        self::assertContains('input_validation_refused', $stages);
        self::assertFalse(self::$executed, 'a schema refusal must not reach the tool');
    }

    // -------------------------------------------------------------- correlation

    #[Test]
    public function every_record_for_one_request_shares_one_correlation_id(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        $ids = array_values(array_unique(array_filter(
            array_column(array_column($this->auditRows(), 'attributes'), 'correlation_id'),
        )));

        self::assertCount(1, $ids, 'Pre- and post-execution records must correlate.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16,}$/', (string) $ids[0]);
    }

    #[Test]
    public function the_tier_is_recorded_so_public_and_write_traffic_are_distinguishable(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        $tiers = array_column(array_column($this->auditRows(), 'attributes'), 'tier');

        self::assertContains('write', $tiers);
    }

    // ----------------------------------------------------------------- redaction

    #[Test]
    public function no_exception_detail_reaches_the_audit_store(): void
    {
        $this->call(
            $this->endpoint($this->account(7), $this->registry($this->throwingTool())),
            'tools/call',
            ['name' => 'article.boom', 'arguments' => []],
        );

        $bytes = $this->auditBytes();

        self::assertStringNotContainsString('sup3rs3cret', $bytes);
        self::assertStringNotContainsString('dsn=', $bytes);
        self::assertStringNotContainsString('10.9.9.9', $bytes);
    }

    #[Test]
    public function the_bearer_token_never_reaches_the_audit_store(): void
    {
        $this->call($this->endpoint($this->account(7)), 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);

        self::assertStringNotContainsString(self::TOKEN, $this->auditBytes());
    }
}
