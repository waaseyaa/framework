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

    #[Test]
    public function from_config_daily_writes_dated_file(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_daily_' . uniqid();
        mkdir($dir, 0o775, true);
        $basePath = $dir . '/app.log';

        try {
            $config = [
                'default' => 'daily',
                'channels' => [
                    'daily' => [
                        'type' => 'daily',
                        'path' => $basePath,
                        'level' => 'debug',
                        'formatter' => 'text',
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('daily line');

            $glob = glob($dir . '/app-*.log');
            $this->assertIsArray($glob);
            $this->assertCount(1, $glob);
            $this->assertStringContainsString('daily line', (string) file_get_contents($glob[0]));
        } finally {
            if (is_dir($dir)) {
                $files = glob($dir . '/*') ?: [];
                foreach ($files as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
                rmdir($dir);
            }
        }
    }

    #[Test]
    public function from_config_handler_string_is_type_alias_for_file_channel(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_handler_alias_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'log',
                'channels' => [
                    'log' => [
                        'handler' => 'file',
                        'path' => $tmpFile,
                        'level' => 'debug',
                        'formatter' => 'text',
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('alias handler key');

            $this->assertFileExists($tmpFile);
            $this->assertStringContainsString('alias handler key', (string) file_get_contents($tmpFile));
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function from_config_fingers_crossed_accepts_nested_key_for_child_handler(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_fc_nested_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'fc',
                'channels' => [
                    'fc' => [
                        'type' => 'fingers_crossed',
                        'action_level' => 'error',
                        'level' => 'debug',
                        'formatter' => 'text',
                        'nested' => [
                            'type' => 'file',
                            'path' => $tmpFile,
                            'level' => 'debug',
                            'formatter' => 'text',
                        ],
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('via nested key');
            $manager->error('fire');

            $this->assertFileExists($tmpFile);
            $content = file_get_contents($tmpFile);
            $this->assertStringContainsString('via nested key', $content);
            $this->assertStringContainsString('fire', $content);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function from_config_fingers_crossed_flushes_on_action_level(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_fingers_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'fc',
                'channels' => [
                    'fc' => [
                        'type' => 'fingers_crossed',
                        'action_level' => 'error',
                        'level' => 'debug',
                        'formatter' => 'text',
                        'handler' => [
                            'type' => 'file',
                            'path' => $tmpFile,
                            'level' => 'debug',
                            'formatter' => 'text',
                        ],
                    ],
                ],
            ];

            $manager = LogManager::fromConfig($config);
            $manager->info('buffered');
            $this->assertFileDoesNotExist($tmpFile);
            $manager->error('trigger');
            $this->assertFileExists($tmpFile);
            $content = file_get_contents($tmpFile);
            $this->assertStringContainsString('buffered', $content);
            $this->assertStringContainsString('trigger', $content);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    #[Test]
    public function add_global_processor_at_runtime(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_addproc_test_' . uniqid() . '.log';

        try {
            $config = [
                'default' => 'file',
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
            $manager->addGlobalProcessor(
                new \Waaseyaa\Foundation\Log\Processor\RequestContextProcessor('GET', '/api/nodes', 'req-test-1'),
            );
            $manager->info('runtime processor test');

            $content = trim(file_get_contents($tmpFile));
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('GET', $decoded['context']['http_method']);
            $this->assertSame('/api/nodes', $decoded['context']['uri']);
            $this->assertSame('req-test-1', $decoded['context']['request_id']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Integration: RedactorProcessor is always-on after fromConfig() — no explicit 'redact'
     * processor config needed.  A record logged with a denylisted context key must arrive at
     * the handler with the value replaced by '[REDACTED]'.
     *
     * This test validates the security default added in the "foundation wave-2 WP1" hardening:
     * credentials must never reach disk (or the S3 sovereignty sink) in cleartext.
     */
    #[Test]
    public function from_config_redactor_is_on_by_default_without_explicit_config(): void
    {
        $tmpFile = sys_get_temp_dir() . '/waaseyaa_redact_default_' . uniqid() . '.log';

        try {
            // No 'processors' key — redaction must be active without being opt-in.
            $config = [
                'default' => 'file',
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
            $manager->info('login attempt', [
                'user_id'       => 42,
                'password'      => 'hunter2',
                'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9',
            ]);

            $content = trim(file_get_contents($tmpFile));
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Benign key must survive untouched.
            $this->assertSame(42, $decoded['context']['user_id']);

            // Denylisted keys must be redacted — no explicit processor config was provided.
            $this->assertSame('[REDACTED]', $decoded['context']['password']);
            $this->assertSame('[REDACTED]', $decoded['context']['Authorization']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
