<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance\Fixtures;

/** Private filesystem/session namespace for one measured page workload. */
final class IsolatedPageSession
{
    /** @var array<string, mixed>|null */
    private static ?array $original = null;

    public static function start(string $projectRoot, string $page): void
    {
        if (self::$original !== null) {
            throw new \LogicException('An isolated benchmark session is already active.');
        }

        self::$original = [
            'active' => session_status() === PHP_SESSION_ACTIVE,
            'save_path' => (string) ini_get('session.save_path'),
            'use_cookies' => (string) ini_get('session.use_cookies'),
            'cache_limiter' => (string) ini_get('session.cache_limiter'),
            'name' => session_name(),
            'id' => session_id(),
            'session_defined' => array_key_exists('_SESSION', $GLOBALS),
            'session' => $GLOBALS['_SESSION'] ?? null,
        ];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $namespace = hash('sha256', $projectRoot . "\0" . $page);
        $directory = $projectRoot . '/storage/performance-sessions/' . $namespace;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new \RuntimeException(sprintf('Could not create isolated benchmark session directory: %s', $directory));
        }

        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_save_path($directory);
        session_id('field-read-page-' . substr($namespace, 0, 32));
        if (!session_start()) {
            throw new \RuntimeException('Could not start an isolated benchmark page session.');
        }

        // A retained project must still begin every page from an exact state.
        $_SESSION = [];
    }

    public static function restore(): void
    {
        $original = self::$original;
        if ($original === null) {
            return;
        }
        self::$original = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ini_set('session.use_cookies', (string) $original['use_cookies']);
        ini_set('session.cache_limiter', (string) $original['cache_limiter']);
        session_save_path((string) $original['save_path']);
        session_name((string) $original['name']);
        session_id((string) $original['id']);

        if ($original['active'] === true) {
            if (!session_start()) {
                throw new \RuntimeException('Could not restore the original PHP session.');
            }
            $_SESSION = is_array($original['session']) ? $original['session'] : [];

            return;
        }
        if ($original['session_defined'] === true) {
            $GLOBALS['_SESSION'] = $original['session'];
        } else {
            unset($GLOBALS['_SESSION']);
        }
    }
}
