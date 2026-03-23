<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\FileLogger;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    #[Test]
    public function writes_formatted_line_with_timestamp(): void
    {
        $logger = new FileLogger($this->logFile);
        $logger->error('Something failed');

        $content = file_get_contents($this->logFile);
        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}\] \[error\] Something failed\n$/',
            $content,
        );
    }

    #[Test]
    public function includes_context_json(): void
    {
        $logger = new FileLogger($this->logFile);
        $logger->warning('Low disk', ['percent' => 95]);

        $content = file_get_contents($this->logFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('[warning] Low disk {"percent":95}', $content);
    }

    #[Test]
    public function respects_minimum_level_filter(): void
    {
        $logger = new FileLogger($this->logFile, minimumLevel: LogLevel::WARNING);

        $logger->debug('ignored');
        $logger->info('ignored');
        $logger->notice('ignored');
        $logger->warning('kept');
        $logger->error('kept');

        $content = file_get_contents($this->logFile);
        $this->assertIsString($content);
        $this->assertStringNotContainsString('ignored', $content);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(2, $lines);
    }

    #[Test]
    public function appends_to_existing_file(): void
    {
        $logger = new FileLogger($this->logFile);
        $logger->info('first');
        $logger->info('second');

        $content = file_get_contents($this->logFile);
        $this->assertIsString($content);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(2, $lines);
    }
}
