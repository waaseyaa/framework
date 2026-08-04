<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * #2177 F4 acceptance blocker: the ENDPOINT's own contract must fail closed.
 *
 * `McpServiceProvider` already refuses to wire a durable write tier without a
 * ledger, but any caller can construct `McpEndpoint` directly. Before this
 * guard, `durableAudit: true` with `auditLedger: null` silently fell back to
 * {@see NullStrictAuditLedger} — a write surface that LOOKS durably audited
 * and records nothing, which is exactly the state the provider refuses to
 * create. The construction itself is the wiring error, so the construction is
 * where it is refused.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointDurableAuditContractTest extends TestCase
{
    #[Test]
    public function durable_audit_without_a_ledger_is_refused_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/durable/i');

        new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            durableAudit: true,
        );
    }

    #[Test]
    public function durable_audit_with_the_record_nothing_ledger_is_refused_at_construction(): void
    {
        // An explicit NullStrictAuditLedger is the same hole one step removed:
        // its own docblock says it must never back a surface that mutates.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/durable/i');

        new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            auditLedger: new NullStrictAuditLedger(),
            durableAudit: true,
        );
    }

    #[Test]
    public function a_best_effort_endpoint_still_constructs_without_a_ledger(): void
    {
        // The public read-only tier's documented shape: no ledger, no durability.
        $endpoint = new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
        );

        self::assertInstanceOf(McpEndpoint::class, $endpoint);
    }

    #[Test]
    public function a_durable_endpoint_with_a_real_ledger_constructs(): void
    {
        $ledger = new class implements StrictAuditLedgerInterface {
            public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
            {
                return new StrictAuditReceipt('r1', $reservation->correlationId);
            }

            public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void {}

            public function record(StrictAuditReservation $reservation, AuditStage $stage): void {}
        };

        $endpoint = new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            auditLedger: $ledger,
            durableAudit: true,
        );

        self::assertInstanceOf(McpEndpoint::class, $endpoint);
    }

    /**
     * The documented refusal guarantee, held to its word: a refusal that is
     * already safe (here a 401 — no side effect can follow) is returned even
     * when the durable terminal record fails, and even when the ledger breaks
     * its own contract by throwing something other than
     * StrictAuditLedgerException. Anything narrower converts an audit outage
     * into a wider denial of service — the same rationale as the
     * catch-\Throwable on the finalize path.
     */
    #[Test]
    public function a_refusal_survives_a_ledger_that_breaks_its_exception_contract(): void
    {
        $auth = $this->createMock(McpAuthInterface::class);
        $auth->method('authenticate')->willReturn(null);

        $contractBreakingLedger = new class implements StrictAuditLedgerInterface {
            public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
            {
                throw new \RuntimeException('not a StrictAuditLedgerException');
            }

            public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
            {
                throw new \RuntimeException('not a StrictAuditLedgerException');
            }

            public function record(StrictAuditReservation $reservation, AuditStage $stage): void
            {
                throw new \RuntimeException('not a StrictAuditLedgerException');
            }
        };

        $endpoint = new McpEndpoint(
            auth: $auth,
            agentRegistry: $this->emptyRegistry(),
            auditLedger: $contractBreakingLedger,
            durableAudit: true,
        );

        $request = \Symfony\Component\HttpFoundation\Request::create(
            '/mcp/write',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer bad'],
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        );
        $response = $endpoint->handle($this->createMock(\Waaseyaa\Access\AccountInterface::class), $request);

        self::assertSame(401, $response->statusCode, 'The refusal must survive the failed terminal record.');
        self::assertSame(-32001, \json_decode($response->body, true)['error']['code']);
    }

    private function emptyRegistry(): AgentToolRegistryInterface
    {
        return new class implements AgentToolRegistryInterface {
            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function all(): iterable
            {
                return [];
            }
        };
    }
}
