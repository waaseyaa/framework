<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;

/** A tool whose behaviour and redaction each test dictates. */
final class ScriptedTool implements AgentToolInterface
{
    /**
     * @param \Closure(array<string, mixed>): AgentToolResult $handler
     * @param ?\Closure(array<string, mixed>): array<string, mixed> $redactor
     * @param ?\ArrayObject<int, string> $sharedOrder Appended with `execute` when the tool runs.
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        private readonly \Closure $handler,
        private readonly ?\Closure $redactor = null,
        private readonly ?\ArrayObject $sharedOrder = null,
        private readonly array $inputSchema = ['type' => 'object'],
    ) {}

    public function execute(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
    {
        $this->sharedOrder?->append('execute');

        return ($this->handler)($arguments);
    }

    public function dryRun(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }

    public function argumentsForAudit(array $arguments): array
    {
        if ($this->redactor !== null) {
            return ($this->redactor)($arguments);
        }

        return $arguments;
    }

    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    public function description(): string
    {
        return 'A scripted tool.';
    }
}
