<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Security;

use Waaseyaa\Foundation\Security\ApplicationSecret;

/**
 * Resolves the reset/verify/invite HMAC key without using raw application-master bytes,
 * and is the single authority on WHERE that key came from.
 *
 * Two sources, and the difference decides whether the auth package participates in
 * application-master rotation at all:
 *
 *  - **Derived custody.** `auth.token_secret` is absent or empty, so the key is an HKDF
 *    output of {@see ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC}. Outstanding tokens are
 *    signed with material owned by the application master, so rotating that master
 *    invalidates them and the auth package must contribute its drain adapter.
 *  - **Explicit independent custody.** A valid `AUTH_TOKEN_SECRET` was supplied. The
 *    signing key has no relationship to the application master, so rotating the master
 *    cannot invalidate a single outstanding token and the auth package must contribute
 *    NOTHING to application-master rotation. Contributing anyway made independently signed
 *    tokens block a rotation they are unaffected by.
 *
 * Classification lives here, not at the call sites, so `AuthServiceProvider`'s token
 * binding and its rekey contribution can never disagree about which mode is active.
 * {@see self::usesDerivedCustody()} answers the question without materialising the secret.
 *
 * Invalid explicit input is never classified as absent: it throws from both entry points,
 * so a rejected secret can neither silently fall back to derived custody nor silently
 * suppress the drain adapter.
 *
 * @api
 */
final class AuthTokenSecret
{
    public const int MINIMUM_EXPLICIT_LENGTH = 32;

    /**
     * @param mixed $configured Explicit `auth.token_secret` value, or null when the key is absent.
     */
    public static function resolve(
        #[\SensitiveParameter]
        mixed $configured,
        ?ApplicationSecret $applicationSecret,
        string $environment,
    ): string {
        $explicit = self::validatedExplicit($configured, $environment);
        if ($explicit !== null) {
            return $explicit;
        }

        if (!$applicationSecret instanceof ApplicationSecret) {
            throw new \RuntimeException(
                'Auth token HMAC key cannot be derived because application-secret custody is unavailable.',
            );
        }

        return $applicationSecret->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC);
    }

    /**
     * Is the auth-token HMAC key derived from application-master custody?
     *
     * True exactly when {@see self::resolve()} would derive it, so the two can never
     * disagree. Applies the SAME validation, which is why an invalid explicit value
     * throws here rather than reporting "absent": a caller asking this question is
     * deciding whether to contribute to a rotation, and answering "derived" for a secret
     * that was actually supplied-but-rejected would be a fail-open answer.
     *
     * @param mixed $configured Explicit `auth.token_secret` value, or null when absent.
     */
    public static function usesDerivedCustody(
        #[\SensitiveParameter]
        mixed $configured,
        string $environment,
    ): bool {
        return self::validatedExplicit($configured, $environment) === null;
    }

    /**
     * The trimmed, validated explicit secret, or null when the key is genuinely absent.
     *
     * The single classification point. Both public entry points route through it, so
     * "which source is this?" is answered once, by one rule set.
     */
    private static function validatedExplicit(
        #[\SensitiveParameter]
        mixed $configured,
        string $environment,
    ): ?string {
        $explicit = self::explicitValue($configured);
        if ($explicit === null) {
            return null;
        }
        self::assertStrongExplicit($explicit, $environment);

        return $explicit;
    }

    private static function explicitValue(#[\SensitiveParameter] mixed $configured): ?string
    {
        if ($configured === null) {
            return null;
        }
        if (!is_string($configured)) {
            throw self::invalidException('unspecified');
        }

        $trimmed = trim($configured);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function assertStrongExplicit(#[\SensitiveParameter] string $secret, string $environment): void
    {
        $folded = strtolower(str_replace(['-', '_', ' '], '', $secret));
        if ($folded === 'changeme') {
            throw self::invalidException($environment);
        }
        if (strlen($secret) < self::MINIMUM_EXPLICIT_LENGTH) {
            throw self::invalidException($environment);
        }
    }

    private static function invalidException(string $environment): \RuntimeException
    {
        $label = preg_replace('/[^a-z0-9._-]/', '', strtolower($environment)) ?? '';
        if ($label === '') {
            $label = 'unspecified';
        }

        return new \RuntimeException(
            'Explicit auth.token_secret is invalid and is refused in every environment, including '
            . $label
            . '. Provide a trimmed secret of at least '
            . self::MINIMUM_EXPLICIT_LENGTH
            . ' characters that is not a published placeholder, or omit auth.token_secret '
            . 'to derive a versioned HMAC key from application-secret custody.',
        );
    }
}
