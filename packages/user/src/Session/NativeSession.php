<?php

declare(strict_types=1);

namespace Waaseyaa\User\Session;

use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Thin SessionInterface wrapper around PHP's native $_SESSION.
 *
 * Unlike Symfony's Session class which uses namespaced "bags",
 * this reads and writes $_SESSION keys directly, preserving
 * compatibility with code that uses raw $_SESSION (e.g. AuthManager).
 */
final class NativeSession implements SessionInterface
{
    /** @param list<string> $trustedProxies IP addresses allowed to set X-Forwarded-Proto */
    public function __construct(
        private readonly array $trustedProxies = [],
    ) {}

    public function start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        session_set_cookie_params([
            'httponly' => true,
            'secure' => $this->isSecureConnection(),
            'samesite' => 'Lax',
        ]);

        return session_start();
    }

    /**
     * Returns the current session cookie parameters.
     *
     * @return array<string, mixed>
     */
    public function getCookieParams(): array
    {
        return session_get_cookie_params();
    }

    public function getId(): string
    {
        return session_id();
    }

    public function setId(string $id): void
    {
        session_id($id);
    }

    public function getName(): string
    {
        return session_name();
    }

    public function setName(string $name): void
    {
        session_name($name);
    }

    public function invalidate(?int $lifetime = null): bool
    {
        $_SESSION = [];
        return session_regenerate_id(true);
    }

    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        return session_regenerate_id($destroy);
    }

    public function save(): void
    {
        session_write_close();
    }

    public function has(string $name): bool
    {
        return isset($_SESSION[$name]);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $_SESSION[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $_SESSION[$name] = $value;
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function replace(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    public function remove(string $name): mixed
    {
        $value = $_SESSION[$name] ?? null;
        unset($_SESSION[$name]);
        return $value;
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function isSecureConnection(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if ($this->trustedProxies === []) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remoteAddr === '') {
            return false;
        }

        return in_array($remoteAddr, $this->trustedProxies, true)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function registerBag(SessionBagInterface $bag): void
    {
        // No-op — we don't use bags
    }

    public function getBag(string $name): SessionBagInterface
    {
        throw new \RuntimeException('NativeSession does not support session bags. Use get()/set() directly.');
    }

    public function getMetadataBag(): \Symfony\Component\HttpFoundation\Session\Storage\MetadataBag
    {
        throw new \RuntimeException('NativeSession does not support MetadataBag.');
    }
}
