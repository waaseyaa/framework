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
use Waaseyaa\AI\Tools\Error\SanitizedToolError;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Tests\Support\RecordingLogger;

/**
 * F6 — an exception escaping a tool must not tell the caller anything about the
 * server. These fixtures deliberately embed a password and an absolute path in
 * the exception message, and assert both are absent from every byte the caller
 * receives while remaining available to an operator through the log.
 */
#[CoversClass(AgentToolRegistryBridge::class)]
#[CoversClass(SanitizedToolError::class)]
final class AgentToolRegistryBridgeSanitizationTest extends TestCase
{
    // Public because the anonymous tool fixtures below read them from their own
    // scope; a private constant there throws an Error and the leak assertions
    // would then pass against the wrong exception.
    public const string SECRET = 'hunter2-s3cret-pw';
    public const string ABSOLUTE_PATH = '/srv/app/vendor/waaseyaa/entity-storage/src/Driver.php';

    private function principal(): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(7);
        $account->method('hasPermission')->willReturn(true);

        return $account;
    }

    /** A tool whose execute() throws with a credential-bearing message. */
    private function throwingTool(): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                throw new \RuntimeException(sprintf(
                    'SQLSTATE[HY000] [1045] Access denied for user \'cms\'@\'10.0.0.4\' '
                    . '(using password: %s) in %s:812',
                    AgentToolRegistryBridgeSanitizationTest::SECRET,
                    AgentToolRegistryBridgeSanitizationTest::ABSOLUTE_PATH,
                ));
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
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'throws';
            }
        };

        return new AgentTool('thing.boom', 'cap.read', false, false, 'test', ['type' => 'object'], $impl);
    }

    /**
     * A tool that RETURNS a deliberate domain-error envelope, in the shape
     * ContentToolSet emits. This must survive untouched.
     */
    private function domainErrorTool(): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error(json_encode([
                    'code' => 'REVISION_CONFLICT',
                    'message' => 'The item changed since you read it.',
                    'meta' => ['expected_revision_id' => 4, 'current_revision_id' => 9],
                ], JSON_THROW_ON_ERROR));
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
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'domain error';
            }
        };

        return new AgentTool('article.publish', 'cap.read', false, false, 'test', ['type' => 'object'], $impl);
    }

    private function registry(AgentTool ...$tools): ToolRegistryInterface
    {
        $registry = new class implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            public array $map = [];

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
        foreach ($tools as $tool) {
            $registry->register($tool);
        }

        return $registry;
    }

    /** Everything the caller receives, as one string — nothing can hide in a nested key. */
    private function wireBytes(array $envelope): string
    {
        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function an_unhandled_exception_never_leaks_the_secret_or_the_absolute_path(): void
    {
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal());

        $wire = $this->wireBytes($bridge->execute('thing.boom', []));

        self::assertStringNotContainsString(self::SECRET, $wire);
        self::assertStringNotContainsString('/srv/app/vendor', $wire);
        self::assertStringNotContainsString('SQLSTATE', $wire);
        self::assertStringNotContainsString('RuntimeException', $wire);
        self::assertStringNotContainsString('10.0.0.4', $wire);
    }

    #[Test]
    public function the_caller_receives_a_stable_code_and_a_correlation_id(): void
    {
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal());

        $envelope = $bridge->execute('thing.boom', []);
        $body = json_decode($envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($envelope['isError']);
        self::assertSame('INTERNAL_ERROR', $body['code']);
        self::assertSame(SanitizedToolError::MESSAGE, $body['message']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['meta']['correlation_id']);
    }

    #[Test]
    public function safe_diagnostic_metadata_reaches_the_logger_under_the_same_correlation_id(): void
    {
        $logger = new RecordingLogger();
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal(), $logger);

        $envelope = $bridge->execute('thing.boom', []);
        $correlationId = json_decode($envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['meta']['correlation_id'];

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        self::assertSame('error', $level);
        self::assertSame('mcp.tool_execution_failed', $message);
        // The correlation id is the join between response and log.
        self::assertSame($correlationId, $context['correlation_id']);
        self::assertSame('thing.boom', $context['tool']);
        self::assertSame(\RuntimeException::class, $context['exception']);
        self::assertArrayHasKey('file', $context);
        self::assertArrayHasKey('line', $context);
    }

    /**
     * The log is not a private channel — it is shipped, indexed and retained.
     * Moving the credential out of the response and into the log store would
     * relocate the disclosure rather than fix it, so the exception message is
     * absent from BOTH sides.
     */
    #[Test]
    public function the_log_context_carries_no_secret_dsn_ip_or_raw_message(): void
    {
        $logger = new RecordingLogger();
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal(), $logger);

        $bridge->execute('thing.boom', []);

        // The COMPLETE serialized context, so nothing hides in a nested key.
        $logged = $logger->allContextAsString();

        self::assertStringNotContainsString(self::SECRET, $logged);
        self::assertStringNotContainsString(self::ABSOLUTE_PATH, $logged);
        self::assertStringNotContainsString('10.0.0.4', $logged);
        self::assertStringNotContainsString('SQLSTATE', $logged);
        self::assertStringNotContainsString('Access denied', $logged);
        self::assertArrayNotHasKey('message', $logger->records[0][2]);
        self::assertArrayNotHasKey('trace', $logger->records[0][2]);
        self::assertArrayNotHasKey('exception_object', $logger->records[0][2]);
    }

    /** The context is a fixed, audited key set — widening it must be deliberate. */
    #[Test]
    public function the_log_context_key_set_is_closed(): void
    {
        $logger = new RecordingLogger();
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal(), $logger);

        $bridge->execute('thing.boom', []);

        // `code` is present here because RuntimeException's is the integer 0;
        // a non-integer code is dropped entirely (see SanitizedToolErrorTest).
        self::assertSame(
            ['correlation_id', 'tool', 'exception', 'file', 'line', 'code'],
            array_keys($logger->records[0][2]),
        );
    }

    /**
     * No `\Throwable` may be attached: a logger that serializes context objects
     * would walk straight into the message and trace.
     */
    #[Test]
    public function no_throwable_object_is_attached_to_the_log_context(): void
    {
        $logger = new RecordingLogger();
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal(), $logger);

        $bridge->execute('thing.boom', []);

        foreach ($logger->records[0][2] as $key => $value) {
            self::assertNotInstanceOf(\Throwable::class, $value, sprintf('Context key "%s" holds a Throwable.', $key));
            self::assertIsNotObject($value, sprintf('Context key "%s" holds an object.', $key));
        }
    }

    #[Test]
    public function each_failure_gets_its_own_correlation_id(): void
    {
        $bridge = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal());

        $first = json_decode($bridge->execute('thing.boom', [])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($bridge->execute('thing.boom', [])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertNotSame($first['meta']['correlation_id'], $second['meta']['correlation_id']);
    }

    /**
     * Sanitization must not depend on a logger being wired. Without one the
     * caller-visible bytes are identical — the detail is simply discarded.
     */
    #[Test]
    public function the_response_is_sanitized_identically_when_no_logger_is_configured(): void
    {
        $withoutLogger = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal());
        $withLogger = new AgentToolRegistryBridge($this->registry($this->throwingTool()), $this->principal(), new RecordingLogger());

        $a = json_decode($withoutLogger->execute('thing.boom', [])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode($withLogger->execute('thing.boom', [])['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        unset($a['meta']['correlation_id'], $b['meta']['correlation_id']);
        self::assertSame($a, $b);
    }

    /**
     * The load-bearing negative: a deliberate domain envelope is NOT swept up by
     * the sanitizer. ContentToolSet's structured errors stay machine-readable.
     */
    #[Test]
    public function a_deliberate_domain_error_envelope_passes_through_untouched(): void
    {
        $bridge = new AgentToolRegistryBridge($this->registry($this->domainErrorTool()), $this->principal());

        $envelope = $bridge->execute('article.publish', []);
        $body = json_decode($envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($envelope['isError']);
        self::assertSame('REVISION_CONFLICT', $body['code']);
        self::assertSame('The item changed since you read it.', $body['message']);
        self::assertSame(4, $body['meta']['expected_revision_id']);
        self::assertSame(9, $body['meta']['current_revision_id']);
        self::assertArrayNotHasKey('correlation_id', $body['meta']);
    }

    #[Test]
    public function an_unknown_tool_yields_a_structured_code_built_from_the_callers_own_input(): void
    {
        $bridge = new AgentToolRegistryBridge($this->registry(), $this->principal());

        $envelope = $bridge->execute('no.such.tool', []);
        $body = json_decode($envelope['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($envelope['isError']);
        self::assertSame('TOOL_NOT_FOUND', $body['code']);
        self::assertStringContainsString('no.such.tool', $body['message']);
    }
}
