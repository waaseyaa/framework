<?php

declare(strict_types=1);

namespace Waaseyaa\User\Session;

/**
 * Resolved session-cookie policy shared by every cookie the framework mints.
 *
 * `SessionMiddleware` applies this policy to the PHP session cookie ini and
 * `CsrfMiddleware` applies it to the CSRF double-submit cookie (#2149, #3047),
 * so forced `session.cookie.secure`, path/domain/name, and the optional
 * host-bound (`__Host-`) profile govern both cookies instead of drifting.
 *
 * `secure` supports `'auto'`: the caller supplies its own HTTPS detection
 * (SessionMiddleware's `$_SERVER` + trusted-proxy check, CsrfMiddleware's
 * `Request::isSecure()`) and the policy only decides whether config forces
 * the flag either way. Host-bound mode always forces Secure.
 */
final class SessionCookiePolicy
{
    public const DEFAULT_CSRF_COOKIE_NAME = 'XSRF-TOKEN';
    public const HOST_BOUND_SESSION_COOKIE_NAME = '__Host-waaseyaa_session';
    public const HOST_BOUND_CSRF_COOKIE_NAME = '__Host-XSRF-TOKEN';

    /**
     * Secure-by-default cookie options, previously private to
     * SessionMiddleware. Keys: httponly (bool), secure (bool|'auto'),
     * samesite (string, '' opts out), use_strict_mode (bool), name (?string),
     * csrf_name (string), path (string), domain (?string), host_bound (bool).
     *
     * @var array<string, bool|string>
     */
    private const array SECURE_COOKIE_DEFAULTS = [
        'httponly' => true,
        'secure' => 'auto',
        'samesite' => 'Lax',
        'use_strict_mode' => true,
        'path' => '/',
        'csrf_name' => self::DEFAULT_CSRF_COOKIE_NAME,
        'host_bound' => false,
    ];

    /** @var array<string, mixed> */
    private readonly array $options;

    /** @var array<string, mixed> */
    private readonly array $explicitOptions;

    /**
     * @param array<string, mixed>|null $options Raw `session.cookie` config;
     *        explicit keys win, hardened defaults fill the rest.
     */
    public function __construct(?array $options = null)
    {
        $this->explicitOptions = $options ?? [];
        $this->options = $this->explicitOptions + self::SECURE_COOKIE_DEFAULTS;
        $this->assertConfigurationCompatible();
    }

    public function httpOnly(): bool
    {
        return filter_var($this->options['httponly'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the cookie must carry the Secure attribute for this request.
     *
     * A configured boolean always wins; `'auto'` defers to the caller's
     * request-scheme detection. Host-bound cookies always require Secure.
     */
    public function resolveSecure(bool $requestIsSecure): bool
    {
        if ($this->hostBound()) {
            return true;
        }

        $secure = $this->options['secure'];
        if ($secure === 'auto') {
            return $requestIsSecure;
        }

        return filter_var($secure, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The SameSite attribute value, or null when config opts out (empty or
     * non-string value) and the attribute must be omitted entirely.
     *
     * Unknown values normalize to the hardened 'Lax' default: Symfony's
     * Cookie::withSameSite() throws on anything outside lax/strict/none, so
     * without normalization a single samesite typo would 500 every
     * cookie-attaching response, while the session ini path silently emitted
     * the invalid attribute. One config value must yield one behavior for
     * both consumers.
     */
    public function sameSite(): ?string
    {
        $sameSite = $this->options['samesite'];
        if (!is_string($sameSite) || $sameSite === '') {
            return null;
        }

        return in_array(strtolower($sameSite), ['lax', 'strict', 'none'], true) ? $sameSite : 'Lax';
    }

    public function useStrictMode(): bool
    {
        return filter_var($this->options['use_strict_mode'], FILTER_VALIDATE_BOOLEAN);
    }

    public function hostBound(): bool
    {
        return filter_var($this->options['host_bound'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Session cookie name, or null to leave PHP's `session_name()` default.
     */
    public function sessionName(): ?string
    {
        if ($this->hostBound()) {
            $name = $this->options['name'] ?? null;
            if (!is_string($name) || $name === '') {
                return self::HOST_BOUND_SESSION_COOKIE_NAME;
            }

            return $name;
        }

        $name = $this->options['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    public function csrfName(): string
    {
        if ($this->hostBound()) {
            $name = $this->options['csrf_name'] ?? null;
            if (!is_string($name) || $name === '' || $name === self::DEFAULT_CSRF_COOKIE_NAME) {
                return self::HOST_BOUND_CSRF_COOKIE_NAME;
            }

            return $name;
        }

        $name = $this->options['csrf_name'] ?? null;
        if (!is_string($name) || $name === '') {
            return self::DEFAULT_CSRF_COOKIE_NAME;
        }

        return $name;
    }

    public function path(): string
    {
        $path = $this->options['path'] ?? '/';
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return $path;
    }

    /**
     * Cookie Domain attribute, or null when the attribute must be omitted
     * (browser host-only default). Host-bound mode always returns null.
     */
    public function domain(): ?string
    {
        if ($this->hostBound()) {
            return null;
        }

        $domain = $this->options['domain'] ?? null;
        if (!is_string($domain) || $domain === '') {
            return null;
        }

        return $domain;
    }

    /**
     * Refuse an already-active PHP session whose live name/path/domain/secure
     * disagree with this policy (inherited ini or a foreign bootstrap).
     *
     * Compatibility defaults stay silent unless the operator opted into an
     * explicit name/path/domain or the host-bound profile — existing apps that
     * prestart PHP sessions must keep working when they never touch these keys.
     */
    public function assertCompatibleWithActiveSession(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        $enforceName = $this->hostBound() || $this->hasExplicit('name');
        $enforcePath = $this->hostBound() || $this->hasExplicit('path');
        $enforceDomain = $this->hostBound() || $this->hasExplicit('domain');
        $enforceSecure = $this->hostBound();

        if (!$enforceName && !$enforcePath && !$enforceDomain && !$enforceSecure) {
            return;
        }

        $params = session_get_cookie_params();

        if ($enforceName) {
            $expectedName = $this->sessionName();
            if ($expectedName !== null) {
                $liveName = session_name();
                if (!is_string($liveName) || $liveName !== $expectedName) {
                    throw new InvalidSessionCookiePolicyException(sprintf(
                        'Active session cookie name "%s" is incompatible with configured name "%s".',
                        is_string($liveName) ? $liveName : '',
                        $expectedName,
                    ));
                }
            }
        }

        if ($enforcePath) {
            $livePath = (string) ($params['path'] ?? '');
            if ($livePath !== $this->path()) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Active session cookie path "%s" is incompatible with configured path "%s".',
                    $livePath,
                    $this->path(),
                ));
            }
        }

        if ($enforceDomain) {
            $liveDomain = (string) ($params['domain'] ?? '');
            $expectedDomain = $this->domain() ?? '';
            if ($liveDomain !== $expectedDomain) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Active session cookie domain "%s" is incompatible with configured domain "%s".',
                    $liveDomain,
                    $expectedDomain,
                ));
            }
        }

        if ($enforceSecure && empty($params['secure'])) {
            throw new InvalidSessionCookiePolicyException(
                'Active session cookie is not Secure; host-bound (__Host-) cookies require Secure.',
            );
        }
    }

    private function hasExplicit(string $key): bool
    {
        return array_key_exists($key, $this->explicitOptions);
    }

    private function assertConfigurationCompatible(): void
    {
        if (!$this->hostBound()) {
            $csrfName = $this->options['csrf_name'] ?? self::DEFAULT_CSRF_COOKIE_NAME;
            if (is_string($csrfName) && $csrfName !== '' && !$this->isValidCookieName($csrfName)) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Invalid session.cookie.csrf_name "%s".',
                    $csrfName,
                ));
            }

            $name = $this->options['name'] ?? null;
            if (is_string($name) && $name !== '' && !$this->isValidCookieName($name)) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Invalid session.cookie.name "%s".',
                    $name,
                ));
            }

            return;
        }

        $path = $this->path();
        if ($path !== '/') {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Host-bound session cookies require path "/", got "%s".',
                $path,
            ));
        }

        $domain = $this->options['domain'] ?? null;
        if (is_string($domain) && $domain !== '') {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Host-bound session cookies must omit Domain; got "%s".',
                $domain,
            ));
        }

        $secure = $this->options['secure'];
        if ($secure !== 'auto' && !filter_var($secure, FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidSessionCookiePolicyException(
                'Host-bound session cookies require secure=true (or auto); secure=false is incompatible.',
            );
        }

        $sessionName = $this->sessionName();
        if ($sessionName === null || !str_starts_with($sessionName, '__Host-')) {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Host-bound session cookie name must use the __Host- prefix; got "%s".',
                (string) $sessionName,
            ));
        }
        if (!$this->isValidCookieName($sessionName)) {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Invalid host-bound session.cookie.name "%s".',
                $sessionName,
            ));
        }

        $csrfName = $this->csrfName();
        if (!str_starts_with($csrfName, '__Host-')) {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Host-bound CSRF cookie name must use the __Host- prefix; got "%s".',
                $csrfName,
            ));
        }
        if (!$this->isValidCookieName($csrfName)) {
            throw new InvalidSessionCookiePolicyException(sprintf(
                'Invalid host-bound session.cookie.csrf_name "%s".',
                $csrfName,
            ));
        }
    }

    private function isValidCookieName(string $name): bool
    {
        // RFC 6265 cookie-name token; reject separators that break Set-Cookie.
        return $name !== '' && !preg_match('/[\x00-\x20\x7f\(\)<>@,;:\\"\/\[\]\?=\{\}]/', $name);
    }
}
