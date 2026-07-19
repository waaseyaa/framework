<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;

#[CoversClass(AgentRun::class)]
final class AgentRunTest extends TestCase
{
    #[Test]
    public function constructorHardcodesEntityTypeAndKeys(): void
    {
        $run = new AgentRun(['id' => 'abc']);

        self::assertSame('agent_run', $run->getEntityTypeId());
    }

    #[Test]
    public function getStatusResolvesStoredString(): void
    {
        $run = new AgentRun(['id' => 'abc', 'status' => 'running']);

        self::assertSame(RunStatus::Running, $run->getStatus());
    }

    #[Test]
    public function getStatusReturnsExistingEnumInstance(): void
    {
        $run = new AgentRun(['id' => 'abc', 'status' => RunStatus::Completed]);

        self::assertSame(RunStatus::Completed, $run->getStatus());
    }

    #[Test]
    public function getDestructiveApprovalDefaultsToNoneWhenMissing(): void
    {
        $run = new AgentRun(['id' => 'abc']);

        self::assertSame(HitlMode::None, $run->getDestructiveApproval());
    }

    #[Test]
    public function getAccountIdRequiresAnEstablishedReadContext(): void
    {
        $run = new AgentRun(['id' => 'abc', 'account_id' => '42']);

        $this->expectException(\Waaseyaa\Entity\Exception\MissingFieldReadContext::class);
        $run->getAccountId();
    }

    #[Test]
    public function isTerminalAgreesWithRunStatusTerminals(): void
    {
        foreach (RunStatus::cases() as $case) {
            $run = new AgentRun(['id' => 'abc', 'status' => $case->value]);
            self::assertSame(
                $case->isTerminal(),
                $run->isTerminal(),
                'AgentRun::isTerminal() must agree with RunStatus for ' . $case->value,
            );
        }
    }
}
