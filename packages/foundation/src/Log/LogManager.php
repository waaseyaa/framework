<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

use Waaseyaa\Foundation\Log\Formatter\FormatterInterface;
use Waaseyaa\Foundation\Log\Formatter\JsonFormatter;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\Handler\FileHandler;
use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\Handler\NullHandler;
use Waaseyaa\Foundation\Log\Handler\StackHandler;
use Waaseyaa\Foundation\Log\Handler\StreamHandler;
use Waaseyaa\Foundation\Log\Processor\HostnameProcessor;
use Waaseyaa\Foundation\Log\Processor\MemoryUsageProcessor;
use Waaseyaa\Foundation\Log\Processor\ProcessorInterface;
use Waaseyaa\Foundation\Log\Processor\RequestIdProcessor;

final class LogManager implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<string, HandlerInterface> */
    private array $handlers = [];

    /** @var list<ProcessorInterface> */
    private array $globalProcessors = [];

    /** @var array<string, list<ProcessorInterface>> */
    private array $channelProcessors = [];

    private string $defaultChannel;

    /**
     * Create a LogManager from a single default handler (Phase A compatibility).
     */
    public function __construct(LoggerInterface|HandlerInterface $default)
    {
        if ($default instanceof HandlerInterface) {
            $this->handlers['default'] = $default;
        } else {
            // Wrap legacy LoggerInterface in an adapter handler.
            $this->handlers['default'] = new LegacyLoggerHandler($default);
        }
        $this->defaultChannel = 'default';
    }

    /**
     * Build a LogManager from logging config.
     *
     * @param array<string, mixed> $config The 'logging' section of waaseyaa.php
     */
    public static function fromConfig(array $config): self
    {
        $channelConfigs = $config['channels'] ?? [];
        $defaultName = $config['default'] ?? 'default';

        if ($channelConfigs === []) {
            return new self(new ErrorLogHandler());
        }

        // Build global processors.
        $globalProcessors = [];
        foreach (($config['processors'] ?? []) as $processorName) {
            $processor = self::buildProcessor($processorName);
            if ($processor !== null) {
                $globalProcessors[] = $processor;
            }
        }

        // First pass: build non-stack handlers.
        $handlers = [];
        $channelProcessorMap = [];
        $stackConfigs = [];
        foreach ($channelConfigs as $name => $channelConfig) {
            $type = $channelConfig['type'] ?? 'errorlog';
            if ($type === 'stack') {
                $stackConfigs[$name] = $channelConfig;
                continue;
            }
            $handlers[$name] = self::buildHandler($channelConfig);

            // Per-channel processors.
            $perChannel = [];
            foreach (($channelConfig['processors'] ?? []) as $processorName) {
                $processor = self::buildProcessor($processorName);
                if ($processor !== null) {
                    $perChannel[] = $processor;
                }
            }
            if ($perChannel !== []) {
                $channelProcessorMap[$name] = $perChannel;
            }
        }

        // Second pass: build stack handlers.
        foreach ($stackConfigs as $name => $channelConfig) {
            $stackChannels = $channelConfig['channels'] ?? [];
            $stackHandlers = [];
            foreach ($stackChannels as $ref) {
                if (isset($handlers[$ref])) {
                    $stackHandlers[] = $handlers[$ref];
                } else {
                    error_log(sprintf('[log] Stack channel "%s" references unknown channel "%s"', $name, $ref));
                }
            }
            $handlers[$name] = new StackHandler(...$stackHandlers);
        }

        $manager = new self($handlers[$defaultName] ?? new ErrorLogHandler());

        foreach ($handlers as $name => $handler) {
            $manager->handlers[$name] = $handler;
        }

        $manager->defaultChannel = $defaultName;
        $manager->globalProcessors = $globalProcessors;
        $manager->channelProcessors = $channelProcessorMap;

        return $manager;
    }

    /**
     * Return a logger for the given channel name.
     *
     * Unknown channels fall back to the default channel.
     */
    public function channel(string $name): LoggerInterface
    {
        $handler = $this->handlers[$name] ?? $this->handlers[$this->defaultChannel];

        // Merge global + per-channel processors.
        $processors = array_merge(
            $this->globalProcessors,
            $this->channelProcessors[$name] ?? [],
        );

        return new ChannelLogger($name, $handler, $processors);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $record = new LogRecord(
            level: $level,
            message: (string) $message,
            context: $context,
            channel: $this->defaultChannel,
        );

        // Run global processors on default channel.
        foreach ($this->globalProcessors as $processor) {
            try {
                $record = $processor->process($record);
            } catch (\Throwable $e) {
                error_log(sprintf('[log] Processor %s failed: %s', $processor::class, $e->getMessage()));
            }
        }

        // Run per-channel processors for default channel.
        foreach (($this->channelProcessors[$this->defaultChannel] ?? []) as $processor) {
            try {
                $record = $processor->process($record);
            } catch (\Throwable $e) {
                error_log(sprintf('[log] Processor %s failed: %s', $processor::class, $e->getMessage()));
            }
        }

        $this->handlers[$this->defaultChannel]->handle($record);
    }

    public function addGlobalProcessor(ProcessorInterface $processor): void
    {
        $this->globalProcessors[] = $processor;
    }

    private static function buildHandler(array $config): HandlerInterface
    {
        $type = $config['type'] ?? 'errorlog';
        $level = LogLevel::fromName((string) ($config['level'] ?? 'debug')) ?? LogLevel::DEBUG;
        $formatter = self::buildFormatter($config['formatter'] ?? 'text');

        return match ($type) {
            'errorlog' => new ErrorLogHandler(formatter: $formatter, minimumLevel: $level),
            'file' => new FileHandler(
                filePath: $config['path'] ?? 'storage/logs/waaseyaa.log',
                formatter: $formatter,
                minimumLevel: $level,
            ),
            'stream' => new StreamHandler(
                stream: fopen($config['path'] ?? 'php://stderr', 'a') ?: fopen('php://stderr', 'a'),
                formatter: $formatter,
                minimumLevel: $level,
            ),
            'null' => new NullHandler(),
            default => new ErrorLogHandler(formatter: $formatter, minimumLevel: $level),
        };
    }

    private static function buildFormatter(string $name): FormatterInterface
    {
        return match ($name) {
            'json' => new JsonFormatter(),
            default => new TextFormatter(),
        };
    }

    private static function buildProcessor(string $name): ?ProcessorInterface
    {
        return match ($name) {
            'request_id' => new RequestIdProcessor(),
            'hostname' => new HostnameProcessor(),
            'memory_usage' => new MemoryUsageProcessor(),
            default => null,
        };
    }
}
