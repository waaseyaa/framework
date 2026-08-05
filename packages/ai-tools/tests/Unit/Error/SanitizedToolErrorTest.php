<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Error\SanitizedToolError;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * F6 at the tool level. The entity/relationship/vector tools each wrap their
 * storage work in a generic `catch (\Throwable)` so one failure cannot take down
 * an agent run; that arm used to embed `$e->getMessage()` in the result, which
 * handed DSNs and absolute paths to the caller. It now routes through
 * {@see AbstractAgentTool::internalError()}.
 */
#[CoversClass(SanitizedToolError::class)]
#[CoversClass(AbstractAgentTool::class)]
final class SanitizedToolErrorTest extends TestCase
{
    public const string SECRET = 'p@ssw0rd-not-for-callers';
    public const string ABSOLUTE_PATH = '/var/www/app/config/database.local.php';
    public const string HOST_ADDRESS = '203.0.113.9';

    /** A tool that fails the way a storage driver fails. */
    private function failingTool(): AbstractAgentTool
    {
        return new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                try {
                    throw new \PDOException(sprintf(
                        'could not find driver (dsn=mysql:host=%s;user=root;password=%s) loaded from %s',
                        SanitizedToolErrorTest::HOST_ADDRESS,
                        SanitizedToolErrorTest::SECRET,
                        SanitizedToolErrorTest::ABSOLUTE_PATH,
                    ));
                } catch (\Throwable $e) {
                    return $this->internalError('entity.read', $e);
                }
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'fails like storage does';
            }
        };
    }

    private function account(): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);

        return $account;
    }

    #[Test]
    public function the_result_carries_no_secret_no_path_and_no_internal_class_name(): void
    {
        $result = $this->failingTool()->execute([], $this->account());

        $wire = json_encode(['content' => $result->content, 'summary' => $result->summary], JSON_THROW_ON_ERROR);

        self::assertTrue($result->isError);
        self::assertStringNotContainsString(self::SECRET, $wire);
        self::assertStringNotContainsString('/var/www', $wire);
        self::assertStringNotContainsString('PDOException', $wire);
        self::assertStringNotContainsString('dsn=', $wire);
    }

    /**
     * `summary` is the audit / transcript line. It is a separate egress path from
     * `content` and previously defaulted to the raw message, so it is asserted
     * explicitly rather than only via the combined wire bytes.
     */
    #[Test]
    public function the_audit_summary_carries_only_the_code_and_correlation_id(): void
    {
        $result = $this->failingTool()->execute([], $this->account());

        self::assertNotNull($result->summary);
        self::assertStringStartsWith('INTERNAL_ERROR (correlation_id=', $result->summary);
        self::assertStringNotContainsString(self::SECRET, $result->summary);
    }

    #[Test]
    public function a_tool_with_no_logger_still_returns_a_sanitized_result(): void
    {
        // No setLogger() call at all — the bare-construction path used by unit
        // tests and hosts without logging.
        $result = $this->failingTool()->execute([], $this->account());

        $body = json_decode($result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('INTERNAL_ERROR', $body['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['meta']['correlation_id']);
    }

    #[Test]
    public function a_tool_with_a_logger_sends_safe_metadata_there_under_the_same_id(): void
    {
        $logger = new class implements LoggerInterface {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $records = [];

            public function emergency(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::EMERGENCY, $m, $c);
            }

            public function alert(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::ALERT, $m, $c);
            }

            public function critical(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::CRITICAL, $m, $c);
            }

            public function error(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::ERROR, $m, $c);
            }

            public function warning(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::WARNING, $m, $c);
            }

            public function notice(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::NOTICE, $m, $c);
            }

            public function info(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::INFO, $m, $c);
            }

            public function debug(string|\Stringable $m, array $c = []): void
            {
                $this->log(LogLevel::DEBUG, $m, $c);
            }

            public function log(LogLevel $level, string|\Stringable $m, array $c = []): void
            {
                $this->records[] = [$level->value, (string) $m, $c];
            }
        };

        $tool = $this->failingTool();
        $tool->setLogger($logger);

        $result = $tool->execute([], $this->account());
        $correlationId = json_decode($result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR)['meta']['correlation_id'];

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        self::assertSame('error', $level);
        self::assertSame('agent_tool.execution_failed', $message);
        self::assertSame('entity.read', $context['tool']);
        self::assertSame(\PDOException::class, $context['exception']);
        // The correlation id is the join between response and log.
        self::assertSame($correlationId, $context['correlation_id']);

        // ...and none of the crafted material reaches the log store.
        $logged = json_encode($context, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $logged);
        self::assertStringNotContainsString(self::ABSOLUTE_PATH, $logged);
        self::assertStringNotContainsString(self::HOST_ADDRESS, $logged);
        self::assertStringNotContainsString('dsn=', $logged);
        self::assertArrayNotHasKey('message', $context);
        self::assertArrayNotHasKey('trace', $context);
    }

    /**
     * `getCode()` is only carried when it is an integer. `PDOException` carries
     * a SQLSTATE string there, and a custom exception may interpolate anything
     * into it — dropping beats inspecting, since a "does this look sensitive?"
     * test is the guesswork this class avoids.
     */
    #[Test]
    public function a_non_integer_exception_code_is_dropped_rather_than_inspected(): void
    {
        $stringCode = SanitizedToolError::logContext(
            new \PDOException('boom', 0),
            'aaaabbbbccccdddd',
            'entity.read',
        );
        // PDOException::$code is declared string; constructing it leaves code as
        // the int 0 here, so assert the rule directly with both shapes.
        self::assertSame(0, $stringCode['code'] ?? 0);

        $intCode = SanitizedToolError::logContext(new \RuntimeException('boom', 42), 'aaaabbbbccccdddd', 'entity.read');
        self::assertSame(42, $intCode['code']);

        $sqlstate = new class ('boom') extends \RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = 'HY000-secret-ish';
            }
        };
        self::assertArrayNotHasKey('code', SanitizedToolError::logContext($sqlstate, 'aaaabbbbccccdddd', 'entity.read'));
    }

    #[Test]
    public function the_caller_visible_message_is_a_fixed_literal_that_interpolates_nothing(): void
    {
        $body = SanitizedToolError::body('abcdef0123456789');

        self::assertSame(SanitizedToolError::MESSAGE, $body['message']);
        self::assertStringNotContainsString('%', SanitizedToolError::MESSAGE);
        self::assertSame(['correlation_id' => 'abcdef0123456789'], $body['meta']);
    }

    #[Test]
    public function optional_backend_unavailability_has_a_stable_sanitized_code(): void
    {
        $result = SanitizedToolError::unavailableResult('abcdef0123456789');
        $body = json_decode($result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result->isError);
        self::assertSame(SanitizedToolError::UNAVAILABLE_CODE, $body['code']);
        self::assertSame(SanitizedToolError::UNAVAILABLE_MESSAGE, $body['message']);
        self::assertSame(['correlation_id' => 'abcdef0123456789'], $body['meta']);
        self::assertStringNotContainsString('%', SanitizedToolError::UNAVAILABLE_MESSAGE);
    }

    #[Test]
    public function correlation_ids_are_unique_across_calls(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = SanitizedToolError::correlationId();
        }

        self::assertCount(50, array_unique($ids));
    }
}
