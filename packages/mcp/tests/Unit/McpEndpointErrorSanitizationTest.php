<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\Tests\Support\RecordingLogger;

/**
 * F6 end-to-end: the assertion is on the RAW HTTP response body, so nothing can
 * hide in a nested key, a summary field, or a JSON-escaped form that a decoded
 * assertion would miss.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointErrorSanitizationTest extends TestCase
{
    public const string SECRET = 'db-p4ssword-abcxyz';
    public const string ABSOLUTE_PATH = '/opt/waaseyaa/current/config/secrets.php';
    public const string HOST_ADDRESS = '198.51.100.24';
    private const string BEARER = 'bearer-token-do-not-log-me';

    private function principal(): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(3);
        $account->method('hasPermission')->willReturn(true);

        return $account;
    }

    private function endpoint(?RecordingLogger $logger): McpEndpoint
    {
        $auth = $this->createMock(McpAuthInterface::class);
        $auth->method('authenticate')->willReturn($this->principal());

        return new McpEndpoint(
            auth: $auth,
            agentRegistry: $this->registry(),
            logger: $logger,
        );
    }

    private function registry(): ToolRegistryInterface
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                throw new \RuntimeException(sprintf(
                    'Connection failed: dsn=pgsql:host=%s;dbname=cms;password=%s; config read from %s',
                    McpEndpointErrorSanitizationTest::HOST_ADDRESS,
                    McpEndpointErrorSanitizationTest::SECRET,
                    McpEndpointErrorSanitizationTest::ABSOLUTE_PATH,
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
                return ['type' => 'object', 'additionalProperties' => true];
            }

            public function description(): string
            {
                return 'explodes';
            }
        };

        $tool = new AgentTool('thing.boom', 'cap.read', false, false, 'test', $impl->inputSchema(), $impl);

        return new class ($tool) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            public function __construct(AgentTool $tool)
            {
                $this->map[$tool->name] = $tool;
            }

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
    }

    private function call(McpEndpoint $endpoint): HttpResponse
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'thing.boom',
                'arguments' => ['password' => self::SECRET, 'note' => 'x'],
            ],
        ], JSON_THROW_ON_ERROR);

        $request = HttpRequest::create(
            '/mcp',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . self::BEARER],
            $body,
        );

        return $endpoint->serve($this->principal(), $request);
    }

    #[Test]
    public function the_raw_jsonrpc_response_contains_neither_the_secret_nor_the_absolute_path(): void
    {
        $raw = (string) $this->call($this->endpoint(null))->getContent();

        self::assertStringNotContainsString(self::SECRET, $raw);
        self::assertStringNotContainsString('/opt/waaseyaa', $raw);
        // JSON-escaped slashes would evade a naive substring check.
        self::assertStringNotContainsString('\/opt\/waaseyaa', $raw);
        self::assertStringNotContainsString(self::HOST_ADDRESS, $raw);
        self::assertStringNotContainsString('dsn=', $raw);
        self::assertStringNotContainsString('RuntimeException', $raw);
        self::assertStringNotContainsString('Connection failed', $raw);
    }

    #[Test]
    public function the_response_is_a_stable_internal_error_with_a_correlation_id(): void
    {
        $raw = (string) $this->call($this->endpoint(null))->getContent();
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $inner = json_decode($body['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($body['result']['isError']);
        self::assertSame('INTERNAL_ERROR', $inner['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $inner['meta']['correlation_id']);
    }

    #[Test]
    public function the_same_sanitized_response_is_produced_without_a_configured_logger(): void
    {
        $withoutLogger = json_decode(
            json_decode((string) $this->call($this->endpoint(null))->getContent(), true, 512, JSON_THROW_ON_ERROR)
                ['result']['content'][0]['text'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $withLogger = json_decode(
            json_decode((string) $this->call($this->endpoint(new RecordingLogger()))->getContent(), true, 512, JSON_THROW_ON_ERROR)
                ['result']['content'][0]['text'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($withoutLogger['code'], $withLogger['code']);
        self::assertSame($withoutLogger['message'], $withLogger['message']);
    }

    #[Test]
    public function the_operator_log_receives_safe_diagnostic_metadata(): void
    {
        $logger = new RecordingLogger();
        $this->call($this->endpoint($logger));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        self::assertSame('error', $level);
        self::assertSame('mcp.tool_execution_failed', $message);
        self::assertSame('thing.boom', $context['tool']);
        self::assertSame(\RuntimeException::class, $context['exception']);
        self::assertArrayHasKey('file', $context);
        self::assertArrayHasKey('line', $context);
    }

    /**
     * The log is a second egress path, not a private one — it is shipped to
     * aggregators, indexed and retained. None of the crafted material may reach
     * it: not the password, not the DSN, not the host address, not the raw
     * message, not the bearer token, and not the call arguments.
     */
    #[Test]
    public function the_log_contains_no_secret_dsn_ip_bearer_token_or_arguments(): void
    {
        $logger = new RecordingLogger();
        $this->call($this->endpoint($logger));

        // The COMPLETE serialized context.
        $logged = $logger->allContextAsString();

        self::assertStringNotContainsString(self::SECRET, $logged);
        self::assertStringNotContainsString(self::ABSOLUTE_PATH, $logged);
        self::assertStringNotContainsString('/opt/waaseyaa', $logged);
        self::assertStringNotContainsString(self::HOST_ADDRESS, $logged);
        self::assertStringNotContainsString(self::BEARER, $logged);
        self::assertStringNotContainsString('Connection failed', $logged);
        self::assertArrayNotHasKey('arguments', $logger->records[0][2]);
        self::assertArrayNotHasKey('authorization', $logger->records[0][2]);
        self::assertArrayNotHasKey('message', $logger->records[0][2]);
        self::assertArrayNotHasKey('trace', $logger->records[0][2]);
    }

    /** The context is a fixed, audited key set — widening it needs deliberate review. */
    #[Test]
    public function the_log_context_key_set_is_closed(): void
    {
        $logger = new RecordingLogger();
        $this->call($this->endpoint($logger));

        // `code` is present here because RuntimeException's is the integer 0;
        // a non-integer code is dropped entirely (see SanitizedToolErrorTest).
        self::assertSame(
            ['correlation_id', 'tool', 'exception', 'file', 'line', 'code'],
            array_keys($logger->records[0][2]),
        );
    }

    /** The correlation id is the only join between the two sides — it must match. */
    #[Test]
    public function the_correlation_id_is_identical_in_the_response_and_the_log(): void
    {
        $logger = new RecordingLogger();
        $response = $this->call($this->endpoint($logger));

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $fromResponse = json_decode($body['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)
            ['meta']['correlation_id'];

        self::assertSame($fromResponse, $logger->records[0][2]['correlation_id']);
    }
}
