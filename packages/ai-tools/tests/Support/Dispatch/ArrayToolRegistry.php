<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/** An in-memory catalogue with no discovery behaviour. */
final class ArrayToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    /** @param list<AgentTool> $tools */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    public function register(AgentTool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): AgentTool
    {
        return $this->tools[$name] ?? throw ToolNotFoundException::forName($name);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function all(): iterable
    {
        yield from array_values($this->tools);
    }
}
