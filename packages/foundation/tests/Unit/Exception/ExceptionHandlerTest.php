<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Exception;

use Waaseyaa\Foundation\Exception\AuthenticationException;
use Waaseyaa\Foundation\Exception\ExceptionHandler;
use Waaseyaa\Foundation\Exception\RequestContext;
use Waaseyaa\Foundation\Exception\StorageException;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spy logger that captures all log records for assertions.
 */
final class CapturingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: LogLevel, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

#[CoversClass(ExceptionHandler::class)]
#[CoversClass(RequestContext::class)]
final class ExceptionHandlerTest extends TestCase
{
    #[Test]
    public function renders_waaseyaa_exception_as_json_api_error(): void
    {
        $handler = new ExceptionHandler();
        $e = new StorageException('Database is down');

        $result = $handler->render($e);

        $this->assertArrayHasKey('errors', $result);
        $this->assertSame('waaseyaa:storage/error', $result['errors'][0]['type']);
        $this->assertSame(503, $result['errors'][0]['status']);
    }

    /**
     * Updated from the OLD leaky contract (detail = raw getMessage()) to the new safe-by-default
     * contract (detail = static 'Internal Server Error' in production/default mode).
     * foundation wave-2 WP2: ExceptionHandler information-leak remediation.
     */
    #[Test]
    public function renders_generic_exception_as_internal_error(): void
    {
        $handler = new ExceptionHandler();
        $e = new \RuntimeException('Something went wrong');

        $result = $handler->render($e);

        $this->assertSame('waaseyaa:internal-error', $result['errors'][0]['type']);
        $this->assertSame(500, $result['errors'][0]['status']);
        // Production default: detail MUST be a static safe string, not the raw exception message.
        $this->assertSame('Internal Server Error', $result['errors'][0]['detail']);
        $this->assertStringNotContainsString('Something went wrong', (string) json_encode($result));
    }

    #[Test]
    public function includes_request_id_in_response(): void
    {
        $context = new RequestContext(requestId: 'req-abc-123');
        $handler = new ExceptionHandler($context);
        $e = new StorageException('Database is down');

        $result = $handler->render($e);

        $this->assertSame('req-abc-123', $result['errors'][0]['instance']);
    }

    #[Test]
    public function renders_cli_error_as_formatted_text(): void
    {
        $handler = new ExceptionHandler(new RequestContext(format: 'cli'));
        $e = new StorageException('Database is down');

        $output = $handler->renderForCli($e);

        $this->assertStringContainsString('StorageException', $output);
        $this->assertStringContainsString('Database is down', $output);
        $this->assertStringContainsString('waaseyaa:storage/error', $output);
    }

    /**
     * Updated from the OLD leaky contract (raw message and file:line always visible) to the new
     * safe-by-default contract (generic CLI output is opaque in production/default mode).
     * foundation wave-2 WP2: ExceptionHandler information-leak remediation.
     */
    #[Test]
    public function renders_generic_cli_error(): void
    {
        // Default (prod) mode: raw message and file:line MUST NOT appear.
        $handler = new ExceptionHandler(new RequestContext(format: 'cli'));
        $e = new \RuntimeException('Something broke');

        $output = $handler->renderForCli($e);

        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringNotContainsString('Something broke', $output);
        $this->assertStringNotContainsString('File:', $output);
    }

    #[Test]
    public function should_report_returns_true_by_default(): void
    {
        $handler = new ExceptionHandler();

        $this->assertTrue($handler->shouldReport(new StorageException('error')));
    }

    #[Test]
    public function dont_report_suppresses_exceptions(): void
    {
        $handler = new ExceptionHandler();
        $handler->dontReport([AuthenticationException::class]);

        $this->assertFalse($handler->shouldReport(new AuthenticationException('Invalid token')));
        $this->assertTrue($handler->shouldReport(new StorageException('error')));
    }

    #[Test]
    public function request_context_detects_api(): void
    {
        $context = new RequestContext(format: 'json');
        $this->assertTrue($context->isApi());
        $this->assertFalse($context->isCli());
    }

    #[Test]
    public function request_context_detects_cli(): void
    {
        $context = new RequestContext(format: 'cli');
        $this->assertTrue($context->isCli());
        $this->assertFalse($context->isApi());
    }

    // -------------------------------------------------------------------------
    // WP2 — new safe-by-default + debug-gated contract tests
    // -------------------------------------------------------------------------

    #[Test]
    public function prod_mode_generic_detail_is_static_safe_string(): void
    {
        $sensitiveMessage = 'SELECT * FROM users WHERE x=1 -- secret';
        $handler = new ExceptionHandler(debug: false);
        $e = new \RuntimeException($sensitiveMessage);

        $result = $handler->render($e);

        // The safe static string must be present.
        $this->assertSame('Internal Server Error', $result['errors'][0]['detail']);
        // The raw message must not appear anywhere in the serialised output.
        $encoded = (string) json_encode($result);
        $this->assertStringNotContainsString($sensitiveMessage, $encoded);
    }

    #[Test]
    public function debug_mode_generic_detail_includes_raw_message(): void
    {
        $sensitiveMessage = 'SELECT * FROM users WHERE x=1 -- secret';
        $handler = new ExceptionHandler(debug: true);
        $e = new \RuntimeException($sensitiveMessage);

        $result = $handler->render($e);

        $this->assertStringContainsString($sensitiveMessage, $result['errors'][0]['detail']);
    }

    #[Test]
    public function prod_mode_cli_does_not_expose_file_or_line(): void
    {
        $handler = new ExceptionHandler(debug: false);
        $e = new \RuntimeException('internal detail');

        $output = $handler->renderForCli($e);

        $this->assertStringNotContainsString('File:', $output);
        $this->assertStringNotContainsString('internal detail', $output);
    }

    #[Test]
    public function debug_mode_cli_includes_file_and_line(): void
    {
        $handler = new ExceptionHandler(debug: true);
        $e = new \RuntimeException('internal detail');

        $output = $handler->renderForCli($e);

        $this->assertStringContainsString('File:', $output);
    }

    #[Test]
    public function logger_receives_real_message_and_context_on_generic_exception(): void
    {
        $logger = new CapturingLogger();
        $sensitiveMessage = 'pg_connect(): Unable to connect — password wrong';
        $handler = new ExceptionHandler(logger: $logger);
        $e = new \RuntimeException($sensitiveMessage);

        $handler->render($e);

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($sensitiveMessage, $logger->records[0]['message']);
        $this->assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        $this->assertArrayHasKey('file', $logger->records[0]['context']);
        $this->assertArrayHasKey('line', $logger->records[0]['context']);
        $this->assertArrayHasKey('trace', $logger->records[0]['context']);
    }

    #[Test]
    public function default_constructor_is_prod_safe_and_existing_callers_stay_valid(): void
    {
        // new ExceptionHandler()  — zero-arg, prod-safe by default
        $h0 = new ExceptionHandler();
        $result = $h0->render(new \RuntimeException('leak test'));
        $this->assertSame('Internal Server Error', $result['errors'][0]['detail']);

        // new ExceptionHandler($context)  — one-arg, backwards-compatible
        $h1 = new ExceptionHandler(new RequestContext(requestId: 'x'));
        $result = $h1->render(new \RuntimeException('leak test 2'));
        $this->assertSame('Internal Server Error', $result['errors'][0]['detail']);
    }

    #[Test]
    public function waaseyaa_exception_render_is_unaffected_by_debug_flag(): void
    {
        $handlerProd = new ExceptionHandler(debug: false);
        $handlerDebug = new ExceptionHandler(debug: true);
        $e = new StorageException('DB is down');

        $prod = $handlerProd->render($e);
        $debug = $handlerDebug->render($e);

        // WaaseyaaException uses toApiError() which is safe — should be identical
        $this->assertSame($prod, $debug);
    }
}
