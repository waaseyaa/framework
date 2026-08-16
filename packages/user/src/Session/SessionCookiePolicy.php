<?php

declare(strict_types=1);

namespace Waaseyaa\User\Session;

/**
 * Resolved session-cookie policy shared by every cookie the framework mints.
 *
 * `SessionMiddleware` applies this policy to the PHP session cookie ini and
 * `CsrfMiddleware` applies it to the `XSRF-TOKEN` cookie (#2149), so a forced
 * `session.cookie.secure => true` governs both cookies instead of the CSRF
 * cookie silently tracking the request scheme. Hardened defaults are always
 * present; any configured key overrides the matching default.
 *
 * `secure` supports `'auto'`: the caller supplies its own HTTPS detection
 * (SessionMiddleware's `$_SERVER` + trusted-proxy check, CsrfMiddleware's
 * `Request::isSecure()`) and the policy only decides whether config forces
 * the flag either way.
 */
final class SessionCookiePolicy
{
    /**
     * Secure-by-default cookie options, previously private to
     * SessionMiddleware. Keys: httponly (bool), secure (bool|'auto'),
     * samesite (string, '' opts out), use_strict_mode (bool).
     *
     * @var array<string, bool|string>
     */
    private const array SECURE_COOKIE_DEFAULTS = [
        'httponly' => true,
        'secure' => 'auto',
        'samesite' => 'Lax',
        'use_strict_mode' => true,
    ];

    /** @var array<string, mixed> */
    private readonly array $options;

    /**
     * @param array<string, mixed>|null $options Raw `session.cookie` config;
     *        explicit keys win, hardened defaults fill the rest.
     */
    public function __construct(?array $options = null)
    {
        $this->options = ($options ?? []) + self::SECURE_COOKIE_DEFAULTS;
    }

    public function httpOnly(): bool
    {
        return filter_var($this->options['httponly'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the cookie must carry the Secure attribute for this request.
     *
     * A configured boolean always wins; `'auto'` defers to the caller's
     * request-scheme detection.
     */
    public function resolveSecure(bool $requestIsSecure): bool
    {
        $secure = $this->options['secure'];
        if ($secure === 'auto') {
            return $requestIsSecure;
        }

        return filter_var($secure, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The SameSite attribute value, or null when config opts out (empty or
     * non-string value) and the attribute must be omitted entirely.
     */
    public function sameSite(): ?string
    {
        $sameSite = $this->options['samesite'];

        return is_string($sameSite) && $sameSite !== '' ? $sameSite : null;
    }

    public function useStrictMode(): bool
    {
        return filter_var($this->options['use_strict_mode'], FILTER_VALIDATE_BOOLEAN);
    }
}
