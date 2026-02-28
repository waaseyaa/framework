<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Exception;

use Aurora\Foundation\Exception\AuthenticationException;
use Aurora\Foundation\Exception\ExceptionHandler;
use Aurora\Foundation\Exception\RequestContext;
use Aurora\Foundation\Exception\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExceptionHandler::class)]
#[CoversClass(RequestContext::class)]
final class ExceptionHandlerTest extends TestCase
{
    #[Test]
    public function renders_aurora_exception_as_json_api_error(): void
    {
        $handler = new ExceptionHandler();
        $e = new StorageException('Database is down');

        $result = $handler->render($e);

        $this->assertArrayHasKey('errors', $result);
        $this->assertSame('aurora:storage/error', $result['errors'][0]['type']);
        $this->assertSame(503, $result['errors'][0]['status']);
    }

    #[Test]
    public function renders_generic_exception_as_internal_error(): void
    {
        $handler = new ExceptionHandler();
        $e = new \RuntimeException('Something went wrong');

        $result = $handler->render($e);

        $this->assertSame('aurora:internal-error', $result['errors'][0]['type']);
        $this->assertSame(500, $result['errors'][0]['status']);
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
        $this->assertStringContainsString('aurora:storage/error', $output);
    }

    #[Test]
    public function renders_generic_cli_error(): void
    {
        $handler = new ExceptionHandler(new RequestContext(format: 'cli'));
        $e = new \RuntimeException('Something broke');

        $output = $handler->renderForCli($e);

        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('Something broke', $output);
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
}
