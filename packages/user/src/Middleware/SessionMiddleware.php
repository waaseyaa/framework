<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Session\NativeSession;

#[AsMiddleware(pipeline: 'http', priority: 30)]
final class SessionMiddleware implements HttpMiddlewareInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param EntityStorageInterface $userStorage Storage for loading user entities.
     * @param AccountInterface|null $devFallback Account returned when no session UID exists. Intended for dev environments only.
     * @param array<string, mixed>|null $sessionCookieOptions Optional session ini overrides before session_start().
     *        Keys: httponly (bool), secure (bool|'auto' — auto uses HTTPS detection), samesite (string), use_strict_mode (bool).
     * @param list<string> $trustedProxies IP addresses allowed to set X-Forwarded-Proto.
     */
    public function __construct(
        private readonly EntityStorageInterface $userStorage,
        private readonly ?AccountInterface $devFallback = null,
        ?LoggerInterface $logger = null,
        private readonly ?array $sessionCookieOptions = null,
        private readonly array $trustedProxies = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        if (session_status() !== \PHP_SESSION_ACTIVE && !$request->attributes->has('_session')) {
            $this->applySessionCookieIni();
            session_start();
        }

        // Attach a session to the Request so controllers can use
        // $request->getSession(). NativeSession reads/writes $_SESSION
        // directly, preserving compatibility with AuthManager.
        if (!$request->hasSession()) {
            $request->setSession(new NativeSession($this->trustedProxies));
        }

        $existingAccount = $request->attributes->get('_account');
        if ($existingAccount instanceof AccountInterface && $existingAccount->isAuthenticated()) {
            return $next->handle($request);
        }

        $account = $this->resolveAccount($request);
        $request->attributes->set('_account', $account);

        return $next->handle($request);
    }

    private function applySessionCookieIni(): void
    {
        if ($this->sessionCookieOptions === null) {
            return;
        }

        $opts = $this->sessionCookieOptions;

        if (array_key_exists('httponly', $opts)) {
            ini_set('session.cookie_httponly', filter_var($opts['httponly'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        if (array_key_exists('secure', $opts)) {
            $secure = $opts['secure'];
            if ($secure === 'auto') {
                $secure = $this->isHttpsRequest();
            } else {
                $secure = filter_var($secure, FILTER_VALIDATE_BOOLEAN);
            }
            ini_set('session.cookie_secure', $secure ? '1' : '0');
        }

        if (array_key_exists('samesite', $opts) && is_string($opts['samesite']) && $opts['samesite'] !== '') {
            ini_set('session.cookie_samesite', $opts['samesite']);
        }

        if (array_key_exists('use_strict_mode', $opts)) {
            ini_set('session.use_strict_mode', filter_var($opts['use_strict_mode'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
    }

    private function isHttpsRequest(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') === 'on') {
            return true;
        }

        if ($this->trustedProxies === []) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($remoteAddr, $this->trustedProxies, true)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function resolveAccount(Request $request): AccountInterface
    {
        $session = $request->attributes->get('_session') ?? ($_SESSION ?? []);
        $uid = $session['waaseyaa_uid'] ?? null;

        if ($uid === null) {
            if ($this->devFallback !== null) {
                $this->logger->info('SessionMiddleware: using dev fallback account (all permissions granted). This should only happen in development.');
                return $this->devFallback;
            }
            return new AnonymousUser();
        }

        try {
            $user = $this->userStorage->load($uid);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('SessionMiddleware: failed to load user %s: %s', $uid, $e->getMessage()));
            return new AnonymousUser();
        }

        if ($user instanceof AccountInterface) {
            return $user;
        }

        return new AnonymousUser();
    }
}
