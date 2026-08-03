<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * #2177 F1 slice B: the endpoint's own approval-gate wiring contract.
 *
 * Any caller can construct `McpEndpoint` directly, so — exactly like the F4
 * durable-audit guard — a gate that LOOKS enforced but has no store to enforce
 * against is refused at construction, not discovered at mutation time.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointApprovalGateContractTest extends TestCase
{
    #[Test]
    public function an_approval_gate_without_a_store_is_refused_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/approval/i');

        new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            auditLedger: $this->workingLedger(),
            durableAudit: true,
            approvalGate: true,
        );
    }

    /**
     * The gate's consume step joins the approval to the strict-ledger receipt
     * of the consuming execution; without durable audit there is no receipt to
     * join, so the combination is a wiring error, not a degraded mode.
     */
    #[Test]
    public function an_approval_gate_without_durable_audit_is_refused_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/approval/i');

        new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            durableAudit: false,
            approvalStore: $this->emptyStore(),
            approvalGate: true,
        );
    }

    #[Test]
    public function a_fully_wired_approval_gate_constructs(): void
    {
        $endpoint = new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
            auditLedger: $this->workingLedger(),
            durableAudit: true,
            approvalStore: $this->emptyStore(),
            approvalGate: true,
        );

        self::assertInstanceOf(McpEndpoint::class, $endpoint);
    }

    #[Test]
    public function an_ungated_endpoint_still_constructs_without_a_store(): void
    {
        $endpoint = new McpEndpoint(
            auth: $this->createMock(McpAuthInterface::class),
            agentRegistry: $this->emptyRegistry(),
        );

        self::assertInstanceOf(McpEndpoint::class, $endpoint);
    }

    /**
     * The stored-vs-computed request-key comparison decides whether an
     * approval matches THIS call. An early-exit byte comparison leaks how many
     * leading bytes of the stored key the caller's tuple reproduces — a timing
     * side channel on approval identity — so the comparison must be
     * constant-time. Timing itself is not unit-assertable, so this pins the
     * mechanism: `hash_equals()` is present and no ordinary `!==`/`===`
     * comparison touches a request key.
     */
    #[Test]
    public function the_request_key_comparison_is_constant_time(): void
    {
        $file = (string) new \ReflectionClass(McpEndpoint::class)->getFileName();
        $source = (string) file_get_contents($file);

        self::assertStringContainsString('hash_equals(', $source);
        self::assertDoesNotMatchRegularExpression(
            '/requestKey\s*(!==|===)/',
            $source,
            'Request keys must be compared with hash_equals(), never with an early-exit operator.',
        );
    }

    private function workingLedger(): StrictAuditLedgerInterface
    {
        return new class implements StrictAuditLedgerInterface {
            public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
            {
                return new StrictAuditReceipt('r1', $reservation->correlationId);
            }

            public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void {}

            public function record(StrictAuditReservation $reservation, AuditStage $stage): void {}
        };
    }

    private function emptyStore(): OperationApprovalStoreInterface
    {
        return new class implements OperationApprovalStoreInterface {
            public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
            {
                throw new \LogicException('not exercised');
            }

            public function find(string $requestId): ?ApprovalRequest
            {
                return null;
            }

            public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
            {
                throw new \LogicException('not exercised');
            }

            public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
            {
                return false;
            }
        };
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
