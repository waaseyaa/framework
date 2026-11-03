<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

/** WordPress 6.8 wp_check_password(), explicit bounded opt-in. @api */
final readonly class WordPressBcryptPasswordVerifier implements LegacyPasswordVerifierInterface
{
    public function name(): string
    {
        return 'wordpress_bcrypt';
    }

    public function supports(string $legacyHash): bool
    {
        return preg_match('/^\$wp\$2y\$(0[4-9]|1[0-4])\$[.\/A-Za-z0-9]{53}$/D', $legacyHash) === 1;
    }

    public function verify(string $password, string $legacyHash): bool
    {
        return strlen($password) <= 4096
            && $this->supports($legacyHash)
            && password_verify(base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true)), substr($legacyHash, 3));
    }
}
