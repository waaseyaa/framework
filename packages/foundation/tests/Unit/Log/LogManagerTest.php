<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ChannelLogger;
use Waaseyaa\Foundation\Log\Formatter\JsonFormatter;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\Handler\NullHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogManager;

#[CoversClass(LogManager::class)]
final class LogManagerTest extends TestCase
{
    #[Test]
    public function implements_logger_interface(): void
    {
        $manager = new LogManager(new NullHandler());

        $this->assertInstanceOf(LoggerInterface::class, $manager);
    }

    #[Test]
    public function log_delegates_to_default_handler(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
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
    public function channel_returns_logger_interface(): void
    {
        $manager = new LogManager(new NullHandler());

        $this->assertInstanceOf(LoggerInterface::class, $manager->channel('default'));
        $this->assertInstanceOf(ChannelLogger::class, $manager->channel('default'));
    }

    #[Test]
    public function channel_unknown_returns_default_channel_logger(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
        );
        $manager = new LogManager($handler);

        $manager->channel('nonexistent')->error('fallback test');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('fallback test', $messages[0]);
    }

    #[Test]
    public function convenience_methods_delegate(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
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

    #[Test]
    public function legacy_logger_interface_accepted(): void
    {
        $messages = [];
        $legacy = new class ($messages) implements LoggerInterface {
            use \Waaseyaa\Foundation\Log\LoggerTrait;

            public function __construct(private array &$messages) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = $level->value . ':' . $message;
            }
        };
        $manager = new LogManager($legacy);

        $manager->error('legacy test');

        $this->assertCount(1, $messages);
        $this->assertSame('error:legacy test', $messages[0]);
    }

    #[Test]
    public function from_config_builds_channels(): void
    {
        $messages = [];
        $config = [
            'default' => 'errorlog',
            'channels' => [
                'errorlog' => [
                    'type' => 'errorlog',
                    'level' => 'warning',
                    'formatter' => 'text',
                ],
            ],
        ];

        $manager = LogManager::fromConfig($config);

        $this->assertInstanceOf(LogManager::class, $manager);
        $this->assertInstanceOf(ChannelLogger::class, $manager->channel('errorlog'));
    }

    #[Test]
    public function from_config_stack_delegates_to_multiple(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_log_test_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'stack',
                'channels' => [
                    'stack' => [
                        'type' => 'stack',
                        'channels' => ['file'],
                    ],
                    'file' => [
                        'type' => 'file',
                        'path' => $tmpFile,
                        'level' => 'debug',
                        'formatter' => 'json',
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->error('stack test');

            $this->assertFileExists($tmpFile);
            $content = file_get_contents($tmpFile);
            $decoded = json_decode($content, true);
            $this->assertSame('stack test', $decoded['message']);
            $this->assertSame('error', $decoded['level']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function from_config_level_routing(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_log_level_test_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'file',
                'channels' => [
                    'file' => [
                        'type' => 'file',
                        'path' => $tmpFile,
                        'level' => 'warning',
                        'formatter' => 'text',
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->debug('should be dropped');
            $manager->info('should be dropped');
            $manager->warning('should pass');
            $manager->error('should pass');

            $this->assertFileExists($tmpFile);
            $lines = array_filter(explode("\n", file_get_contents($tmpFile)));
            $this->assertCount(2, $lines);
            $this->assertStringContainsString('[warning]', $lines[0]);
            $this->assertStringContainsString('[error]', $lines[1]);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function from_config_empty_falls_back_to_default(): void
    {
        $manager = LogManager::fromConfig([]);

        $this->assertInstanceOf(LogManager::class, $manager);
    }

    #[Test]
    public function from_config_global_processors(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_proc_test_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'file',
                'processors' => ['request_id', 'hostname'],
                'channels' => [
                    'file' => [
                        'type' => 'file',
                        'path' => $tmpFile,
                        'level' => 'debug',
                        'formatter' => 'json',
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('processor test');

            $content = trim(file_get_contents($tmpFile));
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('request_id', $decoded['context']);
            $this->assertArrayHasKey('hostname', $decoded['context']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function from_config_per_channel_processors(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_perchan_test_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'file',
                'channels' => [
                    'file' => [
                        'type' => 'file',
                        'path' => $tmpFile,
                        'level' => 'debug',
                        'formatter' => 'json',
                        'processors' => ['memory_usage'],
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('memory test');

            $content = trim(file_get_contents($tmpFile));
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('memory_peak_mb', $decoded['context']);
            $this->assertIsNumeric($decoded['context']['memory_peak_mb']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
