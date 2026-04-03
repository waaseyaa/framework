<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogManager;

final class ConfigLoader
{
    private static ?LoggerInterface $logger = null;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= new LogManager(new ErrorLogHandler());
    }

    /**
     * Load configuration from a PHP file that returns an array.
     *
     * The returned array shape depends on the config file. May be an
     * associative key-value map or a sequential list of objects.
     *
     * @return array<mixed>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = require $path;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Failed to load configuration from %s: %s', $path, $e->getMessage()),
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            self::logger()->warning(sprintf(
                'Config file %s did not return an array (got %s), treating as empty.',
                $path,
                get_debug_type($data),
            ));
            return [];
        }

        return $data;
    }
}
