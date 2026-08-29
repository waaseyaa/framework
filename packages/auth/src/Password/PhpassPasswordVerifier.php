<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

/**
 * Verifies Openwall phpass *portable* hashes — the `$P$` / `$H$` format
 * WordPress stored for every account it created before 6.8.
 *
 * The construction, from Openwall's reference `PasswordHash::crypt_private()`:
 * a 34-character string `$P$` + one iteration character + an 8-character salt +
 * 22 characters of the digest. The digest is `md5(salt . password)` fed back
 * through `md5(digest . password)` `2 ** n` times, where `n` is the position of
 * the iteration character in phpass's own base-64 alphabet, then re-encoded in
 * that alphabet. `$H$` is the same algorithm under the older prefix.
 *
 * ## Why the iteration count is bounded
 *
 * `n` comes out of the stored hash, and the stored hash comes out of a
 * migration — i.e. out of a system this one does not control. Openwall's own
 * range is 7–30, and `2 ** 30` MD5 rounds on a login request is a denial of
 * service that an importer could introduce by accident or a tampered row by
 * intent. This implementation therefore refuses a count above
 * {@see self::DEFAULT_MAX_COUNT_LOG2} rather than performing it.
 *
 * Mind the offset when reasoning about that number. WordPress configures phpass
 * with `iteration_count_log2 = 8`, but `gensalt_private()` writes
 * `itoa64[log2 + 5]`, so a real WordPress hash carries `B` — offset 13 in the
 * alphabet — and `crypt_private()` performs `2 ** 13` = 8,192 rounds. The
 * ceiling below is therefore 16x the value that actually occurs in the field,
 * not 512x.
 *
 * MD5 is not a defensible password hash in 2026. That is the point of
 * {@see LegacyPasswordUpgrade}: this verifier's only job is to accept a
 * migrated credential once, so it can be replaced.
 *
 * @api
 */
final readonly class PhpassPasswordVerifier implements LegacyPasswordVerifierInterface
{
    /** phpass's own base-64 alphabet. Not RFC 4648, and not interchangeable with it. */
    private const string ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** The prefixes this format has shipped under. `$H$` predates `$P$`. */
    private const array PREFIXES = ['$P$', '$H$'];

    /** Openwall's floor. A count below this is not a phpass hash. */
    private const int MIN_COUNT_LOG2 = 7;

    /**
     * 2**17 = 131,072 MD5 rounds — a fraction of a second, and 16x the 2**13
     * a real WordPress hash asks for (see the offset note above).
     */
    public const int DEFAULT_MAX_COUNT_LOG2 = 17;

    /** A portable hash is always exactly this long. */
    private const int HASH_LENGTH = 34;

    public function __construct(
        private int $maxCountLog2 = self::DEFAULT_MAX_COUNT_LOG2,
    ) {
        if ($this->maxCountLog2 < self::MIN_COUNT_LOG2 || $this->maxCountLog2 > 30) {
            throw new \InvalidArgumentException(
                'phpass iteration ceiling must be between ' . self::MIN_COUNT_LOG2 . ' and 30.',
            );
        }
    }

    public function name(): string
    {
        return 'phpass';
    }

    public function supports(string $legacyHash): bool
    {
        return \strlen($legacyHash) === self::HASH_LENGTH
            && \in_array(substr($legacyHash, 0, 3), self::PREFIXES, true);
    }

    public function verify(string $password, string $legacyHash): bool
    {
        if (!$this->supports($legacyHash)) {
            return false;
        }

        $countLog2 = strpos(self::ITOA64, $legacyHash[3]);
        if ($countLog2 === false || $countLog2 < self::MIN_COUNT_LOG2 || $countLog2 > $this->maxCountLog2) {
            return false;
        }

        $salt = substr($legacyHash, 4, 8);
        // The salt is drawn from the same alphabet as the digest; a value
        // outside it did not come from phpass and is not worth 2**n MD5 rounds.
        if (strspn($salt, self::ITOA64) !== 8) {
            return false;
        }

        $digest = md5($salt . $password, true);
        for ($round = 1 << $countLog2; $round > 0; $round--) {
            $digest = md5($digest . $password, true);
        }

        return hash_equals($legacyHash, substr($legacyHash, 0, 12) . self::encode64($digest, 16));
    }

    /**
     * phpass's own base-64 encoder: little-endian 6-bit groups over the
     * alphabet above, with no padding. It is not `base64_encode()` and the two
     * do not produce the same bytes.
     */
    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = \ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < $count) {
                $value |= \ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= \ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}
