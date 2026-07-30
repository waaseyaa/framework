<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;

/**
 * #2145: `tools/call` must enforce the tool's declared JSON Schema
 * server-side BEFORE the handler runs. The bridge is the single choke
 * point every MCP tier dispatches through, so enforcement lives there:
 * schema-invalid arguments yield the established structured error
 * envelope `{code: VALIDATION_FAILED, message, errors}` with
 * `isError: true`, and the tool implementation is never invoked.
 */
#[CoversClass(AgentToolRegistryBridge::class)]
final class AgentToolRegistryBridgeValidationTest extends TestCase
{
    /** @var \ArrayObject<int, array<string, mixed>> Arguments the handler actually received. */
    private \ArrayObject $handlerSink;

    protected function setUp(): void
    {
        $this->handlerSink = new \ArrayObject();
    }

    /** @return list<array<string, mixed>> */
    private function handlerCalls(): array
    {
        return $this->handlerSink->getArrayCopy();
    }

    private function bridge(array $schema): AgentToolRegistryBridge
    {
        $impl = new class ($this->handlerSink, $schema) implements AgentToolInterface {
            /** @param \ArrayObject<int, array<string, mixed>> $calls */
            public function __construct(
                private readonly \ArrayObject $calls,
                private readonly array $schema,
            ) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $this->calls->append($arguments);

                return AgentToolResult::success([['type' => 'text', 'text' => '{"ok":true}']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return $this->schema;
            }

            public function description(): string
            {
                return 'Schema-guarded fixture tool.';
            }
        };

        $tool = new AgentTool(
            name: 'guarded.tool',
            capability: 'tool.test',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema,
            impl: $impl,
        );

        $registry = new class ($tool) implements ToolRegistryInterface {
            public function __construct(private readonly AgentTool $tool) {}

            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                return $name === $this->tool->name ? $this->tool : throw new ToolNotFoundException($name);
            }

            public function has(string $name): bool
            {
                return $name === $this->tool->name;
            }

            public function all(): iterable
            {
                return [$this->tool->name => $this->tool];
            }
        };

        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);

        return new AgentToolRegistryBridge($registry, $account);
    }

    private const array SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'minLength' => 1],
            'target_revision_id' => ['type' => 'integer', 'minimum' => 1],
        ],
        'required' => ['id', 'target_revision_id'],
        'additionalProperties' => false,
    ];

    /** @return array<string, mixed> Decoded envelope from the first content item. */
    private static function decodeEnvelope(array $result): array
    {
        return \json_decode((string) $result['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function missing_required_arguments_never_reach_the_handler(): void
    {
        $bridge = $this->bridge(self::SCHEMA);

        $result = $bridge->execute('guarded.tool', ['id' => '42']);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame([], $this->handlerCalls(), 'The handler must never see schema-invalid input.');

        $envelope = self::decodeEnvelope($result);
        self::assertSame('VALIDATION_FAILED', $envelope['code']);
        self::assertSame('target_revision_id', $envelope['errors'][0]['field']);
    }

    #[Test]
    public function wrongly_typed_arguments_never_reach_the_handler(): void
    {
        $bridge = $this->bridge(self::SCHEMA);

        $result = $bridge->execute('guarded.tool', ['id' => '42', 'target_revision_id' => 'seven']);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame([], $this->handlerCalls());
        self::assertSame('VALIDATION_FAILED', self::decodeEnvelope($result)['code']);
    }

    #[Test]
    public function additional_properties_never_reach_the_handler(): void
    {
        $bridge = $this->bridge(self::SCHEMA);

        $result = $bridge->execute('guarded.tool', [
            'id' => '42',
            'target_revision_id' => 7,
            'unexpected' => 'x',
        ]);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame([], $this->handlerCalls());

        $envelope = self::decodeEnvelope($result);
        self::assertSame('VALIDATION_FAILED', $envelope['code']);
        self::assertSame('unexpected', $envelope['errors'][0]['field']);
    }

    #[Test]
    public function schema_valid_arguments_execute_the_handler(): void
    {
        $bridge = $this->bridge(self::SCHEMA);

        $result = $bridge->execute('guarded.tool', ['id' => '42', 'target_revision_id' => 7]);

        self::assertArrayNotHasKey('isError', $result);
        self::assertSame([["id" => "42", "target_revision_id" => 7]], $this->handlerCalls());
    }

    #[Test]
    public function a_tool_with_an_empty_schema_is_not_validated(): void
    {
        $bridge = $this->bridge([]);

        $result = $bridge->execute('guarded.tool', ['anything' => ['goes' => true]]);

        self::assertArrayNotHasKey('isError', $result);
        self::assertCount(1, $this->handlerCalls());
    }
}
