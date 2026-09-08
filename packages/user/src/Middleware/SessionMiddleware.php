<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\Session\NativeSession;
use Waaseyaa\User\Session\SessionCookiePolicy;
use Waaseyaa\User\User;

#[AsMiddleware(pipeline: 'http', priority: 30)]
final class SessionMiddleware implements HttpMiddlewareInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param EntityRepositoryInterface $userRepository Repository for loading user entities.
     * @param AccountInterface|null $devFallback Account returned when no session UID exists. Intended for dev environments only.
     * @param array<string, mixed>|null $sessionCookieOptions Optional session ini overrides applied before session_start().
     *        Secure-by-default: when null (or when a key is omitted) the hardened defaults
     *        httponly=true, samesite='Lax', use_strict_mode=true, secure='auto', path='/',
     *        csrf_name='XSRF-TOKEN' are applied. Any key supplied here overrides the matching default.
     *        Keys: httponly (bool), secure (bool|'auto'), samesite (string), use_strict_mode (bool),
     *        name (?string), csrf_name (string), path (string), domain (?string), host_bound (bool).
     * @param list<string> $trustedProxies IP addresses allowed to set X-Forwarded-Proto.
     * @param AccountContextInterface|null $accountContext Request-scoped acting-account holder,
     *        mirrored alongside the `_account` attribute on every request (mission
     *        revision-audit-provenance-01KTWY5V FR-002). Null keeps legacy construction working.
     */
    /**
     * @param list<string> $statelessPathPrefixes Path prefixes (e.g. '/docs',
     *        '/llms.txt') whose anonymous GET/HEAD requests never start a PHP
     *        session (issue #2146: informational surfaces should be
     *        cookie-free and shared-cache friendly). A request that already
     *        carries the session cookie resumes normally, so authenticated
     *        visitors keep their identity on stateless pages; every other
     *        method still gets a session, so form and CSRF flows are
     *        untouched. Default [] preserves existing behavior exactly.
     */
    public function __construct(
        private readonly EntityRepositoryInterface $userRepository,
        private readonly ?AccountInterface $devFallback = null,
        ?LoggerInterface $logger = null,
        private readonly ?array $sessionCookieOptions = null,
        private readonly array $trustedProxies = [],
        private readonly ?AccountContextInterface $accountContext = null,
        private readonly array $statelessPathPrefixes = [],
        private readonly ?UserInternalFieldReaderInterface $internalFields = null,
        private readonly ?AuthenticationEligibilityInterface $authenticationEligibility = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        // Resolve the cookie policy before the stateless check so a configured
        // session name (including `__Host-…`) resumes identity even when the
        // global `session_name()` is still PHP's default (#3047).
        $cookiePolicy = new SessionCookiePolicy($this->sessionCookieOptions);
        $statelessRequest = $this->isStatelessRequest($request, $cookiePolicy);
        if (session_status() === \PHP_SESSION_ACTIVE) {
            // Inherited / foreign bootstrap: refuse host-bound or named
            // deployments when the live PHP session already disagrees (#3047).
            $cookiePolicy->assertCompatibleWithActiveSession();
        } elseif (
            !$request->attributes->has('_session')
            && !$statelessRequest
        ) {
            $this->applySessionCookieIni($cookiePolicy);
            // PHP's cache limiter otherwise emits a second Cache-Control field
            // outside the Response object. The response middleware below is
            // the single cache-policy authority for session-bound responses.
            session_cache_limiter('');
            session_start();
        }

        if (!$statelessRequest) {
            $request->attributes->set(ResponseCacheControlMiddleware::SESSION_BOUND_ATTRIBUTE, true);
        }

        // Attach a session to the Request so controllers can use
        // $request->getSession(). NativeSession reads/writes $_SESSION
        // directly, preserving compatibility with AuthManager.
        if (!$request->hasSession()) {
            $request->setSession(new NativeSession(
                $this->trustedProxies,
                $cookiePolicy,
            ));
        }

        $existingAccount = $request->attributes->get('_account');
        if ($existingAccount instanceof AccountInterface && $existingAccount->isAuthenticated()) {
            if ($existingAccount instanceof User && !$this->isActiveAuthenticatedUser($existingAccount)) {
                $account = new AnonymousUser();
                $request->attributes->set('_account', $account);
                $this->accountContext?->set($account);

                return $next->handle($request);
            }

            if (
                $existingAccount instanceof User
                && $this->authenticationEligibility !== null
                && !$this->authenticationEligibility->allows($existingAccount, AuthenticationStage::BearerResolution)
            ) {
                $account = new AnonymousUser();
                $request->attributes->set('_account', $account);
                $this->accountContext?->set($account);
                return $next->handle($request);
            }

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
     * Resolution lives in {@see SessionCookiePolicy} (shared with
     * CsrfMiddleware's CSRF cookie, #2149/#3047): hardened defaults are always
     * applied and any key in $sessionCookieOptions overrides the matching
     * default. `secure => 'auto'` only sets the Secure flag when the request
     * is detected as HTTPS, so plain-HTTP dev sessions keep working. Host-bound
     * mode forces Secure, Path=/, no Domain, and `__Host-` names.
     */
    private function applySessionCookieIni(?SessionCookiePolicy $policy = null): void
    {
        $policy ??= new SessionCookiePolicy($this->sessionCookieOptions);

        $sessionName = $policy->sessionName();
        if ($sessionName !== null) {
            session_name($sessionName);
        }

        ini_set('session.cookie_httponly', $policy->httpOnly() ? '1' : '0');
        ini_set('session.cookie_secure', $policy->resolveSecure($this->isHttpsRequest()) ? '1' : '0');
        ini_set('session.cookie_path', $policy->path());

        $domain = $policy->domain();
        ini_set('session.cookie_domain', $domain ?? '');

        // An explicit override may set samesite to '' to opt out; the default never does.
        $sameSite = $policy->sameSite();
        if ($sameSite !== null) {
            ini_set('session.cookie_samesite', $sameSite);
        }

        ini_set('session.use_strict_mode', $policy->useStrictMode() ? '1' : '0');

        // Align session_set_cookie_params with the same policy. Skip when PHP
        // cookies are disabled (test harnesses) — ini_set above still applies.
        // SameSite opt-out (null) must omit the key so an existing ini value is
        // not overwritten with an empty string.
        if (filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOLEAN)) {
            $params = [
                'lifetime' => (int) (session_get_cookie_params()['lifetime'] ?? 0),
                'path' => $policy->path(),
                'domain' => $domain ?? '',
                'secure' => $policy->resolveSecure($this->isHttpsRequest()),
                'httponly' => $policy->httpOnly(),
            ];
            if ($sameSite !== null) {
                $params['samesite'] = $sameSite;
            }
            session_set_cookie_params($params);
        }
    }

    /**
     * True when this request must not create a session: a GET/HEAD request
     * for a configured stateless path prefix that does not already carry
     * the session cookie. With no active session, CsrfMiddleware's
     * token-presence guard also skips the XSRF cookie, so matching
     * responses are entirely Set-Cookie free.
     *
     * Cookie presence is resolved against the configured {@see SessionCookiePolicy}
     * name first (so `__Host-waaseyaa_session` resumes before `session_name()`
     * is applied), then the live PHP session name / `PHPSESSID` fallback.
     */
    private function isStatelessRequest(Request $request, SessionCookiePolicy $policy): bool
    {
        if ($this->statelessPathPrefixes === []) {
            return false;
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        if ($this->requestCarriesSessionCookie($request, $policy)) {
            return false;
        }

        $path = $request->getPathInfo();
        foreach ($this->statelessPathPrefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }

            // '/' means the root path, NOT a prefix of every path (#2154).
            // Prefix-matching it would silently make the whole site stateless
            // — including /admin/login, a GET that must mint a CSRF token —
            // and an app disabling sessions everywhere would not use this
            // feature to do it.
            if ($prefix === '/') {
                if ($path === '/') {
                    return true;
                }

                continue;
            }

            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function requestCarriesSessionCookie(Request $request, SessionCookiePolicy $policy): bool
    {
        $configuredName = $policy->sessionName();
        if ($configuredName !== null && $request->cookies->has($configuredName)) {
            return true;
        }

        $liveName = session_name();
        if (is_string($liveName) && $liveName !== '' && $request->cookies->has($liveName)) {
            return true;
        }

        // PHP's default when session_name() has never been set.
        return $configuredName === null && $request->cookies->has('PHPSESSID');
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
        $uid = $session[AuthenticatedSession::USER_ID_KEY] ?? null;

        if ($uid === null) {
            if ($this->devFallback !== null) {
                $this->logger->info('SessionMiddleware: using dev fallback account (all permissions granted). This should only happen in development.');
                return $this->devFallback;
            }
            return new AnonymousUser();
        }

        $generation = $session[AuthenticatedSession::GENERATION_KEY] ?? null;
        if (!is_int($generation)) {
            $this->clearSessionIdentity($request, $session);
            return new AnonymousUser();
        }

        try {
            $user = $this->userRepository->find((string) $uid);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('SessionMiddleware: failed to load user %s: %s', $uid, $e->getMessage()));
            return new AnonymousUser();
        }

        if ($user instanceof User) {
            $currentGeneration = $this->internalFields?->sessionIdentity($user)->generation;
            if ($currentGeneration === null || $generation !== $currentGeneration) {
                $this->clearSessionIdentity($request, $session);
                $this->logger->info(sprintf('SessionMiddleware: revoked stale session for user %s.', $uid));
                return new AnonymousUser();
            }

            if (!$this->isActiveAuthenticatedUser($user)) {
                $this->clearSessionIdentity($request, $session);
                $this->logger->info(sprintf('SessionMiddleware: revoked inactive session for user %s.', $uid));
                return new AnonymousUser();
            }

            if (
                $this->authenticationEligibility !== null
                && !$this->authenticationEligibility->allows($user, AuthenticationStage::ExistingSession)
            ) {
                $this->clearSessionIdentity($request, $session);
                $this->logger->info(sprintf('SessionMiddleware: revoked ineligible session for user %s.', $uid));
                return new AnonymousUser();
            }

            return $user;
        }

        return new AnonymousUser();
    }

    private function isActiveAuthenticatedUser(User $user): bool
    {
        if ($this->internalFields === null) {
            return false;
        }

        return $this->internalFields->verification($user)->active;
    }

    /** @param array<string, mixed> $session */
    private function clearSessionIdentity(Request $request, array $session): void
    {
        unset($session[AuthenticatedSession::USER_ID_KEY], $session[AuthenticatedSession::GENERATION_KEY]);
        if ($request->attributes->has('_session')) {
            $request->attributes->set('_session', $session);
        } else {
            AuthenticatedSession::clearIdentity();
        }
    }
}
