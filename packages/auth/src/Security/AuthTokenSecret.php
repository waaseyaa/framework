<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Security;

use Waaseyaa\Foundation\Security\ApplicationSecret;

/**
 * Resolves the reset/verify/invite HMAC key without using raw application-master bytes.
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
        $explicit = self::explicitValue($configured);
        if ($explicit !== null) {
            self::assertStrongExplicit($explicit, $environment);

            return $explicit;
        }

        if (!$applicationSecret instanceof ApplicationSecret) {
            throw new \RuntimeException(
                'Auth token HMAC key cannot be derived because application-secret custody is unavailable.',
            );
        }

        return $applicationSecret->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC);
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
