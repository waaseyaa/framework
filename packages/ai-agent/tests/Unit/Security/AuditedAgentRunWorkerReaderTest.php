<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Security\AuditedAgentRunWorkerReader;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;

#[CoversClass(AuditedAgentRunWorkerReader::class)]
final class AuditedAgentRunWorkerReaderTest extends TestCase
{
    #[Test]
    public function protectedWorkerPayloadFailsWithoutAReadContext(): void
    {
        $run = $this->fixtureRun();

        $this->expectException(MissingFieldReadContext::class);
        $run->get('prompt');
    }

    #[Test]
    public function reservesTheExactWorkerProjectionBeforeObtainingValues(): void
    {
        $events = [];
        $descriptor = null;
        $ledger = new class ($events, $descriptor) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$events, private ?PrivilegedReadDescriptor &$descriptor) {}

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve';
                $this->descriptor = $descriptor;

                return new PrivilegedReadReceipt('agent-run-worker-read');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:' . $outcome->value;
            }
        };
        $capabilities = new InMemoryCapabilityRegistry();
        $reader = new AuditedAgentRunWorkerReader(new AuditedFieldRead($capabilities, $ledger), $capabilities);

        $fields = $reader->read($this->fixtureRun());

        self::assertSame(42, $fields->accountId);
        self::assertSame('demo', $fields->agentDefinitionId);
        self::assertSame('{"id":"demo"}', $fields->bundleJson);
        self::assertSame('protected prompt', $fields->prompt);
        self::assertSame('protected response', $fields->response);
        self::assertSame('approval_denied', $fields->errorCode);
        self::assertSame('Denied.', $fields->errorMessage);
        self::assertSame(['reserve', 'finalize:succeeded'], $events);
        self::assertNotNull($descriptor);
        self::assertSame(
            ['account_id', 'agent_definition_id', 'bundle_json', 'prompt', 'response', 'error_code', 'error_message'],
            $descriptor->fields,
        );
        self::assertSame(CapabilityActorSemantics::System, $descriptor->actorSemantics);
        self::assertSame('agent-run-worker', $descriptor->actorId);
    }

    private function fixtureRun(): AgentRun
    {
        return new AgentRun([
            'id' => 'run-1',
            'account_id' => 42,
            'agent_definition_id' => 'demo',
            'bundle_json' => '{"id":"demo"}',
            'status' => 'running',
            'destructive_approval' => 'interactive',
            'prompt' => 'protected prompt',
            'response' => 'protected response',
            'error_code' => 'approval_denied',
            'error_message' => 'Denied.',
        ]);
    }
}
