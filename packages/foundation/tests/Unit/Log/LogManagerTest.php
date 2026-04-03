<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(LogManager::class)]
final class LogManagerTest extends TestCase
{
    #[Test]
    public function implements_logger_interface(): void
    {
        $manager = new LogManager(new NullLogger());

        $this->assertInstanceOf(LoggerInterface::class, $manager);
    }

    #[Test]
    public function log_delegates_to_default_channel(): void
    {
        $messages = [];
        $handler = new \Waaseyaa\Foundation\Log\ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
        );
        $manager = new LogManager($handler);

        $manager->log(LogLevel::ERROR, 'test message');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('test message', $messages[0]);
    }

    #[Test]
    public function channel_default_returns_itself(): void
    {
        $handler = new NullLogger();
        $manager = new LogManager($handler);

        $this->assertSame($handler, $manager->channel('default'));
    }

    #[Test]
    public function channel_unknown_returns_default(): void
    {
        $handler = new NullLogger();
        $manager = new LogManager($handler);

        $this->assertSame($handler, $manager->channel('nonexistent'));
    }

    #[Test]
    public function convenience_methods_delegate(): void
    {
        $messages = [];
        $handler = new \Waaseyaa\Foundation\Log\ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
        );
        $manager = new LogManager($handler);

        $manager->error('error msg');
        $manager->warning('warning msg');
        $manager->info('info msg');

        $this->assertCount(3, $messages);
        $this->assertStringContainsString('[error]', $messages[0]);
        $this->assertStringContainsString('[warning]', $messages[1]);
        $this->assertStringContainsString('[info]', $messages[2]);
    }
}
