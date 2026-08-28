<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\ExceptionInterface;

/**
 * Loads a Symfony dotenv cascade into the process environment once.
 *
 * Resolution rules:
 * - A missing base file is silently ignored.
 * - Symfony Dotenv owns parsing, interpolation, comments, exports, and the
 *   `.env.local` / environment-specific cascade.
 * - Values are written consistently to putenv(), $_ENV, and $_SERVER.
 * - Externally injected values win against files.
 * - Each real base path is parsed at most once per process, keeping retained
 *   FrankenPHP workers free of per-request process-global mutation.
 */
final class EnvLoader
{
    /** @var array<string, true> */
    private static array $loadedPaths = [];

    public static function load(string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || isset(self::$loadedPaths[$resolved])) {
            return;
        }

        $processEnvironment = getenv();
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;

        // Symfony treats $_ENV as the existing-value authority. Mirror the
        // process environment first so values injected before PHP startup or by
        // a launcher through putenv() retain the historical "process wins"
        // precedence and resolve interpolation consistently in every store.
        foreach ($processEnvironment as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                continue;
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            new Dotenv()
                ->usePutenv()
                ->loadEnv($resolved, 'APP_ENV', 'production');
        } catch (ExceptionInterface) {
            self::restoreEnvironment($processEnvironment, $envBefore, $serverBefore);
            throw new \RuntimeException('Application environment file is malformed or unreadable.');
        }

        self::$loadedPaths[$resolved] = true;
    }

    /**
     * @param array<string, string> $processEnvironment
     * @param array<string, mixed> $env
     * @param array<string, mixed> $server
     */
    private static function restoreEnvironment(
        #[\SensitiveParameter]
        array $processEnvironment,
        #[\SensitiveParameter]
        array $env,
        #[\SensitiveParameter]
        array $server,
    ): void {
        $current = getenv();
        foreach (array_diff_key($current, $processEnvironment) as $name => $_) {
            putenv($name);
        }
        foreach ($processEnvironment as $name => $value) {
            putenv("{$name}={$value}");
        }
        $_ENV = $env;
        $_SERVER = $server;
    }
}
