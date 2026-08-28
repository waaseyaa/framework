<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Password;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Password\PhpassPasswordVerifier;

/**
 * #2544: phpass portable (`$P$` / `$H$`) verification.
 *
 * ## How the expected values are obtained
 *
 * The fixtures below are produced by {@see self::phpassHash()}, a transcription
 * of Openwall's reference `PasswordHash` *hashing* routine written here in the
 * test. The class under test transcribes the *verification* side independently.
 * Agreement between them is a real cross-check of two separate readings of the
 * same published algorithm — but it is NOT a third-party vector, and this file
 * does not claim to be one. Before rolling this out against a real roster, one
 * hash from that roster should be verified end to end; see the operational
 * rollout section of `docs/upgrade-notes/legacy-password-upgrade.md`.
 *
 * The self-consistency risk that check would catch is narrow, because the two
 * transcriptions disagree structurally: the verifier reconstructs the whole
 * 34-character string and compares it, so a wrong salt slice, a wrong iteration
 * base, or a wrong alphabet fails here rather than cancelling out.
 */
#[CoversClass(PhpassPasswordVerifier::class)]
final class PhpassPasswordVerifierTest extends TestCase
{
    private const string ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * The iteration character a real WordPress hash carries.
     *
     * WordPress configures phpass with `iteration_count_log2 = 8`, but
     * `gensalt_private()` stores `itoa64[log2 + 5]` — so the character is `B`,
     * its alphabet offset is 13, and verification performs `2 ** 13` rounds.
     * Getting that offset wrong is the single easiest way to write a phpass
     * implementation that is self-consistent and rejects every real hash.
     */
    private const string WORDPRESS_COUNT_CHAR = 'B';

    /** The exponent `B` decodes to, and therefore the rounds a real login costs. */
    private const int WORDPRESS_COUNT_LOG2 = 13;

    #[Test]
    public function it_accepts_the_password_a_wordpress_hash_was_made_from(): void
    {
        $hash = self::phpassHash('correct horse battery staple', 'aBcD1234');

        self::assertSame(34, \strlen($hash), 'A portable hash is always 34 characters.');
        self::assertStringStartsWith('$P$' . self::WORDPRESS_COUNT_CHAR, $hash);
        self::assertTrue(new PhpassPasswordVerifier()->verify('correct horse battery staple', $hash));
    }

    #[Test]
    public function it_rejects_the_wrong_password(): void
    {
        $hash = self::phpassHash('correct horse battery staple', 'aBcD1234');

        self::assertFalse(new PhpassPasswordVerifier()->verify('Correct horse battery staple', $hash));
        self::assertFalse(new PhpassPasswordVerifier()->verify('', $hash));
    }

    /** The older prefix is the same algorithm and must verify identically. */
    #[Test]
    public function it_accepts_the_older_h_prefix(): void
    {
        $portable = self::phpassHash('shared secret', 'zZyYxXwW');
        $older = '$H$' . substr($portable, 3);

        self::assertTrue(new PhpassPasswordVerifier()->verify('shared secret', $older));
    }

    /** Passwords are bytes, not characters: a migrated roster is not ASCII. */
    #[Test]
    public function it_verifies_a_non_ascii_password_byte_for_byte(): void
    {
        $password = 'Aanii-nindizhinikaaz ᓇᓄᒃ 🐻';
        $hash = self::phpassHash($password, 'QwErTy12');

        $verifier = new PhpassPasswordVerifier();
        self::assertTrue($verifier->verify($password, $hash));
        self::assertFalse($verifier->verify('Aanii-nindizhinikaaz ᓇᓄᒃ', $hash));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHashes(): iterable
    {
        $valid = self::phpassHash('pw', 'aBcD1234');

        yield 'empty' => [''];
        yield 'a native bcrypt hash' => ['$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV'];
        yield 'a native argon2id hash' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$abcdefghijkl'];
        yield 'a modern WordPress hash' => ['$wp$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV'];
        yield 'the phpass failure sentinel' => ['*0'];
        yield 'the other phpass failure sentinel' => ['*1'];
        yield 'one character short' => [substr($valid, 0, 33)];
        yield 'one character long' => [$valid . 'x'];
        yield 'unknown prefix' => ['$X$' . substr($valid, 3)];
        yield 'salt outside the phpass alphabet' => ['$P$B' . '!!!!!!!!' . substr($valid, 12)];
        yield 'iteration char outside the alphabet' => ['$P$!' . substr($valid, 4)];
        yield 'nul byte' => ["\0" . substr($valid, 1)];
    }

    #[Test]
    #[DataProvider('malformedHashes')]
    public function it_fails_safely_on_a_malformed_or_unsupported_hash(string $stored): void
    {
        $verifier = new PhpassPasswordVerifier();

        // No throw, no notice, no acceptance — the login path cannot afford an
        // exception, and a distinguishable failure would be an oracle.
        self::assertFalse($verifier->verify('pw', $stored));
        self::assertFalse($verifier->verify('', $stored));
    }

    /**
     * The iteration count is read out of a value a migration imported, so an
     * absurd one is refused rather than performed. `2**30` MD5 rounds on a
     * login request is a denial of service.
     */
    #[Test]
    public function it_refuses_an_iteration_count_above_the_ceiling(): void
    {
        // Openwall's own maximum. Indexed out of the alphabet rather than
        // written as a literal so the fixture cannot drift from it.
        $countChar = self::ITOA64[30];
        $hash = '$P$' . $countChar . 'aBcD1234' . substr(self::phpassHash('pw', 'aBcD1234'), 12);

        $verifier = new PhpassPasswordVerifier();
        self::assertTrue($verifier->supports($hash), 'The FORMAT is recognized …');
        self::assertFalse($verifier->verify('pw', $hash), '… but the cost is refused, not performed.');
    }

    #[Test]
    public function it_refuses_an_iteration_count_below_the_phpass_floor(): void
    {
        $hash = '$P$' . self::ITOA64[6] . 'aBcD1234' . substr(self::phpassHash('pw', 'aBcD1234'), 12);

        self::assertFalse(new PhpassPasswordVerifier()->verify('pw', $hash));
    }

    /** A deployment may lower the ceiling; it may not raise it past phpass's own range. */
    #[Test]
    public function the_ceiling_is_configurable_within_the_formats_own_range(): void
    {
        $hash = self::phpassHash('pw', 'aBcD1234'); // stored exponent 13

        self::assertSame(
            self::WORDPRESS_COUNT_LOG2,
            strpos(self::ITOA64, self::WORDPRESS_COUNT_CHAR),
            'A real WordPress hash decodes to exponent 13, not 8 — phpass stores log2 + 5.',
        );
        self::assertTrue(new PhpassPasswordVerifier(maxCountLog2: self::WORDPRESS_COUNT_LOG2)->verify('pw', $hash));
        self::assertFalse(
            new PhpassPasswordVerifier(maxCountLog2: self::WORDPRESS_COUNT_LOG2 - 1)->verify('pw', $hash),
            'A ceiling below the stored count must refuse it.',
        );

        $this->expectException(\InvalidArgumentException::class);
        new PhpassPasswordVerifier(maxCountLog2: 31);
    }

    #[Test]
    public function supports_recognizes_the_format_without_consulting_a_password(): void
    {
        $verifier = new PhpassPasswordVerifier();

        self::assertTrue($verifier->supports(self::phpassHash('pw', 'aBcD1234')));
        self::assertTrue($verifier->supports('$H$' . substr(self::phpassHash('pw', 'aBcD1234'), 3)));
        self::assertFalse($verifier->supports('$2y$10$' . str_repeat('a', 53)));
        self::assertSame('phpass', $verifier->name());
    }

    /**
     * Openwall's reference HASHING routine, transcribed here so the fixtures
     * are not produced by the class under test. See the class docblock.
     */
    private static function phpassHash(string $password, string $salt, string $countChar = self::WORDPRESS_COUNT_CHAR): string
    {
        self::assertSame(8, \strlen($salt));
        $countLog2 = strpos(self::ITOA64, $countChar);
        self::assertIsInt($countLog2);

        $digest = md5($salt . $password, true);
        $rounds = 1 << $countLog2;
        do {
            $digest = md5($digest . $password, true);
        } while (--$rounds);

        return '$P$' . $countChar . $salt . self::encode64($digest, 16);
    }

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
