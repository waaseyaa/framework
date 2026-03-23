<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use Waaseyaa\AI\Agent\AgentAction;
use Waaseyaa\AI\Agent\AgentContext;
use Waaseyaa\AI\Agent\AgentInterface;
use Waaseyaa\AI\Agent\AgentResult;

/**
 * Simple test agent for unit testing purposes.
 */
final class TestAgent implements AgentInterface
{
    private ?AgentResult $executeResult = null;
    private ?AgentResult $dryRunResult = null;
    private ?\Throwable $executeException = null;
    private ?\Throwable $dryRunException = null;

    public function __construct(?AgentResult $defaultResult = null)
    {
        if ($defaultResult !== null) {
            $this->executeResult = $defaultResult;
        }
    }

    public function execute(AgentContext $context): AgentResult
    {
        if ($this->executeException !== null) {
            throw $this->executeException;
        }

        if ($this->executeResult !== null) {
            return $this->executeResult;
        }

        return AgentResult::success(
            message: 'Test agent executed',
            data: ['parameters' => $context->parameters],
            actions: [
                new AgentAction('create', 'Created test entity'),
            ],
        );
    }

    public function dryRun(AgentContext $context): AgentResult
    {
        if ($this->dryRunException !== null) {
            throw $this->dryRunException;
        }

        if ($this->dryRunResult !== null) {
            return $this->dryRunResult;
        }

        return AgentResult::success(
            message: 'Test agent would create entity',
            data: ['parameters' => $context->parameters],
            actions: [
                new AgentAction('create', 'Would create test entity'),
            ],
        );
    }

    public function describe(): string
    {
        return 'A test agent for unit testing';
    }

    public function setExecuteResult(AgentResult $result): void
    {
        $this->executeResult = $result;
    }

    public function setDryRunResult(AgentResult $result): void
    {
        $this->dryRunResult = $result;
    }

    public function setExecuteException(\Throwable $exception): void
    {
        $this->executeException = $exception;
    }

    public function setDryRunException(\Throwable $exception): void
    {
        $this->dryRunException = $exception;
    }
}
