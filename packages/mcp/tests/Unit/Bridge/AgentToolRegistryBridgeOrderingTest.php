<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;

#[CoversClass(AgentToolRegistryBridge::class)]
final class AgentToolRegistryBridgeOrderingTest extends TestCase
{
    #[Test]
    public function protocol_catalogue_is_name_sorted_regardless_of_registry_enumeration_order(): void
    {
        $ascending = $this->bridge(['alpha.read', 'zeta.read']);
        $descending = $this->bridge(['zeta.read', 'alpha.read']);

        self::assertSame(
            ['alpha.read', 'zeta.read'],
            array_map(static fn(AgentTool $tool): string => $tool->name, $ascending->getTools()),
        );
        self::assertSame(
            ['alpha.read', 'zeta.read'],
            array_map(static fn(AgentTool $tool): string => $tool->name, $descending->getTools()),
        );
    }

    /** @param list<string> $names */
    private function bridge(array $names): AgentToolRegistryBridge
    {
        $implementation = $this->createStub(AgentToolInterface::class);
        $tools = array_map(
            static fn(string $name): AgentTool => new AgentTool(
                name: $name,
                capability: 'tool.read',
                destructive: false,
                dryRunSupported: false,
                category: 'test',
                inputSchema: ['type' => 'object'],
                impl: $implementation,
            ),
            $names,
        );
        $registry = new class ($tools) implements ToolRegistryInterface {
            /** @param list<AgentTool> $tools */
            public function __construct(private readonly array $tools) {}

            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->name === $name) {
                        return $tool;
                    }
                }

                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->name === $name) {
                        return true;
                    }
                }

                return false;
            }

            public function all(): iterable
            {
                return $this->tools;
            }
        };
        $account = $this->createStub(AuthorizationPrincipalInterface::class);

        return new AgentToolRegistryBridge($registry, $account);
    }
}
