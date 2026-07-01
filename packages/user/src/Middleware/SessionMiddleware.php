<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
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
     * @param EntityRepositoryInterface $userRepository Repository for loading user entities.
     * @param AccountInterface|null $devFallback Account returned when no session UID exists. Intended for dev environments only.
     * @param array<string, mixed>|null $sessionCookieOptions Optional session ini overrides applied before session_start().
     *        Secure-by-default: when null (or when a key is omitted) the hardened defaults
     *        httponly=true, samesite='Lax', use_strict_mode=true, secure='auto' are applied.
     *        Any key supplied here overrides the matching default.
     *        Keys: httponly (bool), secure (bool|'auto' — auto uses HTTPS detection), samesite (string), use_strict_mode (bool).
     * @param list<string> $trustedProxies IP addresses allowed to set X-Forwarded-Proto.
     * @param AccountContextInterface|null $accountContext Request-scoped acting-account holder,
     *        mirrored alongside the `_account` attribute on every request (mission
     *        revision-audit-provenance-01KTWY5V FR-002). Null keeps legacy construction working.
     */
    public function __construct(
        private readonly EntityRepositoryInterface $userRepository,
        private readonly ?AccountInterface $devFallback = null,
        ?LoggerInterface $logger = null,
        private readonly ?array $sessionCookieOptions = null,
        private readonly array $trustedProxies = [],
        private readonly ?AccountContextInterface $accountContext = null,
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
            // BearerAuthMiddleware (higher priority) already resolved an
            // account — mirror it into the acting-account context too.
            $this->accountContext?->set($existingAccount);
            return $next->handle($request);
        }

        $account = $this->resolveAccount($request);
        $request->attributes->set('_account', $account);
        // HTTP requests are the outermost scope: overwrite unconditionally,
        // never restore. Anonymous (id 0) is an actor and flows through too.
        $this->accountContext?->set($account);

        return $next->handle($request);
    }

    /**
     * Secure-by-default session cookie ini.
     *
     * Hardened defaults are always applied (closing the insecure-by-default
     * session cookie gap); any key in $sessionCookieOptions overrides the
     * matching default. `secure => 'auto'` only sets the Secure flag when the
     * request is detected as HTTPS, so plain-HTTP dev sessions keep working.
     *
     * @var array<string, bool|string>
     */
    private const array SECURE_COOKIE_DEFAULTS = [
        'httponly' => true,
        'secure' => 'auto',
        'samesite' => 'Lax',
        'use_strict_mode' => true,
    ];

    private function applySessionCookieIni(): void
    {
        // Explicit config (left operand) wins; hardened defaults fill the rest,
        // so every key below is guaranteed present (no array_key_exists guard).
        $opts = ($this->sessionCookieOptions ?? []) + self::SECURE_COOKIE_DEFAULTS;

        ini_set('session.cookie_httponly', filter_var($opts['httponly'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');

        $secure = $opts['secure'];
        if ($secure === 'auto') {
            $secure = $this->isHttpsRequest();
        } else {
            $secure = filter_var($secure, FILTER_VALIDATE_BOOLEAN);
        }
        ini_set('session.cookie_secure', $secure ? '1' : '0');

        // An explicit override may set samesite to '' to opt out; the default never does.
        if (is_string($opts['samesite']) && $opts['samesite'] !== '') {
            ini_set('session.cookie_samesite', $opts['samesite']);
        }

        ini_set('session.use_strict_mode', filter_var($opts['use_strict_mode'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
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
        if ($remoteAddr === '') {
            return false;
        }

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
            $user = $this->userRepository->find((string) $uid);
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
