<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration\Audit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Audit\Storage\StrictAuditLedgerSchema;
use Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * The F4 durability guarantee, end to end through the real JSON-RPC boundary
 * against a real SQLite `strict_audit_ledger`.
 *
 * The guarantee under test: **the write tier cannot mutate content without a
 * durable record of the attempt.** Its exact limit — no atomicity between the
 * mutation and the outcome record — is pinned here too, so the crash window is
 * a tested property rather than a claim in a docblock.
 */
#[CoversNothing]
final class McpDurableWriteAuditTest extends TestCase
{
    private const string WRITE_CAP = 'content.publish';
    public const string WRITE_CAP_PUBLIC = self::WRITE_CAP;
    private const string TOKEN = 'write-token';

    /** Incremented every time the destructive fixture actually mutates. */
    public static int $mutations = 0;

    private DBALDatabase $db;

    protected function setUp(): void
    {
        self::$mutations = 0;
        $this->db = DBALDatabase::createSqlite();
        new StrictAuditLedgerSchema($this->db)->ensure();
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        $rows = [];
        $sql = 'SELECT receipt_id, correlation_id, event_type, surface, operation, stage, outcome, '
             . 'actor_uid, descriptor FROM strict_audit_ledger ORDER BY id';
        foreach ($this->db->query($sql) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    private function account(int $id, bool $hasCapability = true): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $p): bool => $hasCapability && $p === self::WRITE_CAP,
        );

        return $account;
    }

    private function writeTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability(McpDurableWriteAuditTest::WRITE_CAP_PUBLIC, $account);
                if ($denied !== null) {
                    return $denied;
                }
                // Stands in for a committed mutation.
                McpDurableWriteAuditTest::$mutations++;

                return AgentToolResult::success([['type' => 'text', 'text' => 'published']]);
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string'], 'token' => ['type' => 'string']],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ];
            }

            public function description(): string
            {
                return 'publishes';
            }
        };

        return new AgentTool('article.publish', self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);
    }

    private function registry(): ToolRegistryInterface
    {
        $tool = $this->writeTool();

        return new class ($tool) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            public function __construct(AgentTool $tool)
            {
                $this->map[$tool->name] = $tool;
            }

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
    }

    private function endpoint(
        ?\Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface $ledger = null,
        bool $durable = true,
        bool $hasCapability = true,
    ): McpEndpoint {
        return new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $this->account(7, $hasCapability)]),
            agentRegistry: new CapabilityScopedToolRegistry($this->registry(), [self::WRITE_CAP]),
            rateLimitTier: 'write',
            auditLedger: $ledger ?? new DatabaseStrictAuditLedger($this->db),
            durableAudit: $durable,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(McpEndpoint $endpoint, array $arguments = ['id' => 'a1']): HttpResponse
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'article.publish', 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR);
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN], $body);

        return $endpoint->serve($this->account(99), $request);
    }

    // -------------------------------------------------------- the core guarantee

    #[Test]
    public function a_successful_write_produces_a_durable_reserved_then_finalized_pair(): void
    {
        $this->callTool($this->endpoint());

        self::assertSame(1, self::$mutations);

        $rows = $this->ledger();
        self::assertCount(2, $rows);

        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertSame('mcp.write', $rows[0]['surface']);
        self::assertSame('article.publish', $rows[0]['operation']);
        self::assertSame(7, (int) $rows[0]['actor_uid']);

        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame(AuditStage::ExecutionSucceeded->value, $rows[1]['stage']);
        self::assertSame('allowed', $rows[1]['outcome']);

        // Both halves share one correlation id.
        self::assertSame($rows[0]['correlation_id'], $rows[1]['correlation_id']);
        self::assertSame($rows[0]['receipt_id'], $rows[1]['receipt_id']);
    }

    /**
     * The load-bearing test: an unpersistable reservation must stop the tool
     * from running at all.
     *
     * The failure is injected REALLY — the ledger points at a database with no
     * `strict_audit_ledger` table, so the INSERT genuinely fails — rather than
     * through a mock that merely promises to throw.
     */
    #[Test]
    public function a_reservation_that_cannot_be_persisted_prevents_the_mutation(): void
    {
        $tableless = DBALDatabase::createSqlite(); // schema deliberately not ensured

        $response = $this->callTool($this->endpoint(new DatabaseStrictAuditLedger($tableless)));

        self::assertSame(
            0,
            self::$mutations,
            'The tool MUST NOT run when its attempt could not be recorded durably.',
        );

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(-32002, $body['error']['code']);
        self::assertStringContainsString('audit trail is unavailable', $body['error']['message']);
    }

    #[Test]
    public function the_audit_unavailable_refusal_leaks_no_exception_detail(): void
    {
        $tableless = DBALDatabase::createSqlite();

        $raw = (string) $this->callTool($this->endpoint(new DatabaseStrictAuditLedger($tableless)))->getContent();

        // F6 must still hold on this new refusal path.
        self::assertStringNotContainsString('strict_audit_ledger', $raw);
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('Exception', $raw);
    }

    // ------------------------------------------------------- outcome correctness

    #[Test]
    public function a_capability_refusal_finalizes_as_authorization_refused(): void
    {
        $this->callTool($this->endpoint(hasCapability: false));

        self::assertSame(0, self::$mutations);

        $rows = $this->ledger();
        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame(AuditStage::AuthorizationRefused->value, $rows[1]['stage']);
        self::assertSame('denied', $rows[1]['outcome']);
    }

    #[Test]
    public function a_schema_refusal_is_recorded_without_ever_reserving(): void
    {
        // `id` is required; omitting it must refuse before the reservation, since
        // nothing will be attempted.
        $this->callTool($this->endpoint(), ['token' => 'nope']);

        self::assertSame(0, self::$mutations);

        $rows = $this->ledger();
        self::assertCount(1, $rows, 'A refusal that never executes is one record, not a pair.');
        self::assertSame('recorded', $rows[0]['event_type']);
        self::assertSame(AuditStage::InputValidationRefused->value, $rows[0]['stage']);
    }

    // ------------------------------------------------------ the crash-window limit

    /**
     * Pins the documented limit. A finalize failure after a committed mutation
     * leaves a dangling reservation: the mutation is NOT retried, NOT rolled
     * back, and the unfinished record is detectable by query.
     */
    #[Test]
    public function a_finalize_failure_leaves_a_detectable_dangling_reservation_and_never_undoes_the_mutation(): void
    {
        $realLedger = new DatabaseStrictAuditLedger($this->db);

        $ledger = new class ($realLedger) implements \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface {
            public function __construct(private readonly \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface $inner) {}

            public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
            {
                return $this->inner->reserve($reservation);
            }

            public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
            {
                // Stands in for the process dying between the tool's own commit
                // and the outcome write.
                throw new StrictAuditLedgerException('simulated crash before finalize');
            }

            public function record(StrictAuditReservation $reservation, AuditStage $stage): void
            {
                $this->inner->record($reservation, $stage);
            }
        };

        $response = $this->callTool($this->endpoint($ledger));

        // The mutation committed exactly once — not retried, not undone.
        self::assertSame(1, self::$mutations);

        // The caller still gets its real result; the audit gap is an operator
        // concern, not a reason to fail a completed write.
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $body);

        // ...and the gap is DETECTABLE: reserved with no finalized.
        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('reserved', $rows[0]['event_type']);

        $dangling = iterator_to_array($this->db->query(
            'SELECT r.receipt_id FROM strict_audit_ledger r '
            . 'LEFT JOIN strict_audit_ledger f '
            . '  ON f.receipt_id = r.receipt_id AND f.event_type = :fin '
            . 'WHERE r.event_type = :res AND f.id IS NULL',
            ['fin' => 'finalized', 'res' => 'reserved'],
        ));
        self::assertCount(1, $dangling, 'A dangling reservation must be findable by query.');
    }

    #[Test]
    public function a_receipt_cannot_be_finalized_twice(): void
    {
        $ledger = new DatabaseStrictAuditLedger($this->db);
        $receipt = $ledger->reserve(new StrictAuditReservation(
            correlationId: 'abcdef0123456789',
            surface: 'mcp.write',
            operation: 'article.publish',
            actorUid: 7,
        ));

        $ledger->finalize($receipt, AuditStage::ExecutionSucceeded);

        // A repeat is a programming error, not a retry — the side effect already ran.
        $this->expectException(\LogicException::class);
        $ledger->finalize($receipt, AuditStage::ExecutionSucceeded);
    }

    #[Test]
    public function an_unreserved_receipt_cannot_be_finalized(): void
    {
        $ledger = new DatabaseStrictAuditLedger($this->db);

        $this->expectException(\LogicException::class);
        $ledger->finalize(new StrictAuditReceipt('never-reserved', 'abcdef0123456789'), AuditStage::ExecutionSucceeded);
    }

    // ------------------------------------------------------------ redaction

    #[Test]
    public function the_ledger_stores_redacted_arguments_and_never_the_bearer_token(): void
    {
        $this->callTool($this->endpoint(), ['id' => 'a1', 'token' => 'sk-secret-value']);

        $bytes = json_encode($this->ledger(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('a1', $bytes, 'safe arguments must survive');
        self::assertStringNotContainsString('sk-secret-value', $bytes, 'argumentsForAudit() redacts `token`');
        self::assertStringNotContainsString(self::TOKEN, $bytes);
    }

    // --------------------------------------------------------- tier separation

    #[Test]
    public function the_public_tier_does_not_write_to_the_strict_ledger(): void
    {
        // durable mode off is the public tier's documented, unchanged behaviour.
        $this->callTool($this->endpoint(durable: false));

        self::assertSame(1, self::$mutations);
        self::assertSame([], $this->ledger(), 'Best-effort tiers must not gain a durable dependency.');
    }
}
