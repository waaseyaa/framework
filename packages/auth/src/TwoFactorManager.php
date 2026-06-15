<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

/**
 * Stateless TOTP + recovery-code primitives (RFC 6238).
 *
 * The high-level orchestration lives in {@see TwoFactorService}; this class
 * is the underlying cryptographic primitive layer and is part of Waaseyaa's
 * public extension surface so consumers can compose their own 2FA flows.
 *
 * @api
 */
final class TwoFactorManager
{
    private const int CODE_LENGTH = 6;
    private const int TIME_STEP = 30;
    private const int WINDOW = 1;
    private const int RECOVERY_CODE_COUNT = 8;
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random Base32-encoded secret.
     */
    public function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        $base32 = '';

        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($bytes); $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $base32 .= self::BASE32_ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        return substr($base32, 0, 32);
    }

    /**
     * Verify a TOTP code against a secret.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->verifyCodeStep($secret, $code) !== null;
    }

    /**
     * Verify a TOTP code and return the absolute time step it matched, or
     * null on no match. The returned step is monotonic and lets callers
     * enforce single-use within the validity window (replay protection):
     * reject any code whose matched step is <= the last step already used.
     */
    public function verifyCodeStep(string $secret, string $code): ?int
    {
        if ($code === '' || strlen($code) !== self::CODE_LENGTH) {
            return null;
        }

        $timeStep = $this->currentTimeStep();

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $candidate = $timeStep + $i;
            $expectedCode = $this->generateCode($secret, $candidate);
            if (hash_equals($expectedCode, $code)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Get the current TOTP code for a secret (for testing).
     */
    public function getCurrentCode(string $secret): string
    {
        return $this->generateCode($secret, $this->currentTimeStep());
    }

    /**
     * Generate an otpauth:// URI for QR code generation.
     */
    public function getQrCodeUri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::CODE_LENGTH,
            'period' => self::TIME_STEP,
        ]);
    }

    /**
     * Generate a set of recovery codes.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        return $codes;
    }

    /**
     * Verify a recovery code against a list of valid codes.
     *
     * @param list<string> $validCodes
     */
    public function verifyRecoveryCode(string $code, array $validCodes): bool
    {
        foreach ($validCodes as $validCode) {
            if (hash_equals($validCode, $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateCode(string $base32Secret, int $timeStep): string
    {
        $secretBytes = $this->base32Decode($base32Secret);
        $timeBytes = pack('N*', 0, $timeStep);
        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::CODE_LENGTH);

        return str_pad((string) $code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function currentTimeStep(): int
    {
        return (int) floor(time() / self::TIME_STEP);
    }

    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos(self::BASE32_ALPHABET, $base32[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }

    private function generateRecoveryCode(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $part1 = '';
        $part2 = '';

        for ($i = 0; $i < 5; $i++) {
            $part1 .= $chars[random_int(0, strlen($chars) - 1)];
            $part2 .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $part1 . '-' . $part2;
    }
}
