<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Formatter\JsonFormatter;
use Waaseyaa\Foundation\Log\Handler\FileHandler;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(FileHandler::class)]
final class FileHandlerTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/waaseyaa_handler_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    #[Test]
    public function handle_writes_to_file(): void
    {
        $handler = new FileHandler($this->tmpFile);

        $handler->handle(new LogRecord(LogLevel::INFO, 'file test'));

        $this->assertFileExists($this->tmpFile);
        $this->assertStringContainsString('file test', file_get_contents($this->tmpFile));
    }

    #[Test]
    public function handle_respects_minimum_level(): void
    {
        $handler = new FileHandler($this->tmpFile, minimumLevel: LogLevel::WARNING);

        $handler->handle(new LogRecord(LogLevel::DEBUG, 'dropped'));

        $this->assertFileDoesNotExist($this->tmpFile);
    }

    #[Test]
    public function handle_uses_provided_formatter(): void
    {
        $handler = new FileHandler($this->tmpFile, new JsonFormatter());

        $handler->handle(new LogRecord(LogLevel::ERROR, 'json test'));

        $content = trim(file_get_contents($this->tmpFile));
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('json test', $decoded['message']);
    }
}
