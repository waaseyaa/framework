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
     *
     * Malformed values are rejected at construction (no silent disable/default):
     * non-boolean `host_bound`, non-string `path`/`domain`, and control
     * characters in path/domain. Documented legacy forms (`true`/`false`,
     * `"1"`/`"0"`/`"on"`/`"off"` for booleans; unset path/domain) still work.
     */
    public function __construct(?array $options = null)
    {
        $this->explicitOptions = $this->normalizeExplicitOptions($options ?? []);
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
     * Refuse an already-active PHP session whose live cookie attributes
     * disagree with this policy (inherited ini or a foreign bootstrap).
     *
     * Compatibility defaults stay silent unless the operator opted into an
     * explicit cookie attribute or the host-bound profile — existing apps that
     * prestart PHP sessions must keep working when they never touch these keys.
     * Host-bound and any explicit secure/httponly/samesite always validate the
     * full effective attribute against `session_get_cookie_params()`.
     */
    public function assertCompatibleWithActiveSession(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        $enforceName = $this->hostBound() || $this->hasExplicit('name');
        $enforcePath = $this->hostBound() || $this->hasExplicit('path');
        $enforceDomain = $this->hostBound() || $this->hasExplicit('domain');
        $enforceSecure = $this->hostBound() || $this->hasExplicitForcedSecure();
        $enforceHttpOnly = $this->hostBound() || $this->hasExplicit('httponly');
        $enforceSameSite = $this->hostBound() || $this->hasExplicit('samesite');

        if (
            !$enforceName
            && !$enforcePath
            && !$enforceDomain
            && !$enforceSecure
            && !$enforceHttpOnly
            && !$enforceSameSite
        ) {
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

        if ($enforceSecure) {
            $expectedSecure = $this->hostBound()
                ? true
                : filter_var($this->options['secure'], FILTER_VALIDATE_BOOLEAN);
            $liveSecure = !empty($params['secure']);
            if ($liveSecure !== $expectedSecure) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Active session cookie Secure=%s is incompatible with configured Secure=%s.',
                    $liveSecure ? 'true' : 'false',
                    $expectedSecure ? 'true' : 'false',
                ));
            }
        }

        if ($enforceHttpOnly) {
            $liveHttpOnly = !empty($params['httponly']);
            if ($liveHttpOnly !== $this->httpOnly()) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Active session cookie HttpOnly=%s is incompatible with configured HttpOnly=%s.',
                    $liveHttpOnly ? 'true' : 'false',
                    $this->httpOnly() ? 'true' : 'false',
                ));
            }
        }

        if ($enforceSameSite) {
            $expectedSameSite = $this->sameSite();
            $liveRaw = $params['samesite'] ?? '';
            $liveSameSite = is_string($liveRaw) && $liveRaw !== '' ? $liveRaw : null;
            if ($expectedSameSite === null) {
                if ($liveSameSite !== null) {
                    throw new InvalidSessionCookiePolicyException(sprintf(
                        'Active session cookie SameSite="%s" is incompatible with configured SameSite opt-out.',
                        $liveSameSite,
                    ));
                }
            } elseif ($liveSameSite === null || strcasecmp($liveSameSite, $expectedSameSite) !== 0) {
                throw new InvalidSessionCookiePolicyException(sprintf(
                    'Active session cookie SameSite="%s" is incompatible with configured SameSite="%s".',
                    $liveSameSite ?? '',
                    $expectedSameSite,
                ));
            }
        }
    }

    private function hasExplicit(string $key): bool
    {
        return array_key_exists($key, $this->explicitOptions);
    }

    /**
     * True when `secure` is explicitly forced to a boolean (not `'auto'`).
     * `'auto'` still follows the request scheme and cannot be compared to a
     * prestarted session without that request context.
     */
    private function hasExplicitForcedSecure(): bool
    {
        if (!$this->hasExplicit('secure')) {
            return false;
        }

        return ($this->explicitOptions['secure'] ?? null) !== 'auto';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeExplicitOptions(array $options): array
    {
        if (array_key_exists('host_bound', $options)) {
            $parsed = filter_var(
                $options['host_bound'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            if ($parsed === null) {
                throw new InvalidSessionCookiePolicyException(
                    'session.cookie.host_bound must be a boolean (or a documented boolean string such as "true"/"false").',
                );
            }
            $options['host_bound'] = $parsed;
        }

        if (array_key_exists('path', $options)) {
            $path = $options['path'];
            if (!is_string($path)) {
                throw new InvalidSessionCookiePolicyException(
                    'session.cookie.path must be a string.',
                );
            }
            if ($path !== '' && !$this->isSafeCookieAttributeValue($path)) {
                throw new InvalidSessionCookiePolicyException(
                    'session.cookie.path must not contain control characters.',
                );
            }
        }

        if (array_key_exists('domain', $options)) {
            $domain = $options['domain'];
            if ($domain !== null && !is_string($domain)) {
                throw new InvalidSessionCookiePolicyException(
                    'session.cookie.domain must be a string or null.',
                );
            }
            if (is_string($domain) && $domain !== '' && !$this->isSafeCookieAttributeValue($domain)) {
                throw new InvalidSessionCookiePolicyException(
                    'session.cookie.domain must not contain control characters.',
                );
            }
        }

        return $options;
    }

    private function isSafeCookieAttributeValue(string $value): bool
    {
        // Reject CR/LF/NUL and other controls that break Set-Cookie framing.
        return !preg_match('/[\x00-\x1f\x7f]/', $value);
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
