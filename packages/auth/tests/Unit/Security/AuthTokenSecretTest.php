<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Security\AuthTokenSecret;
use Waaseyaa\Foundation\Security\ApplicationSecret;

#[CoversClass(AuthTokenSecret::class)]
final class AuthTokenSecretTest extends TestCase
{
    private const string EXPLICIT = 'abcdefghijklmnopqrstuvwxyz012345';

    #[Test]
    public function trims_a_valid_explicit_override(): void
    {
        $master = ApplicationSecret::fromEnvironmentValue('base64:' . base64_encode(random_bytes(32)), 'production');

        self::assertSame(
            self::EXPLICIT,
            AuthTokenSecret::resolve('  ' . self::EXPLICIT . "\n", $master, 'production'),
        );
    }

    #[Test]
    public function whitespace_only_derives_instead_of_using_spaces_as_the_key(): void
    {
        $raw = random_bytes(32);
        $master = ApplicationSecret::fromEnvironmentValue('base64:' . base64_encode($raw), 'testing');
        $resolved = AuthTokenSecret::resolve(" \t\n", $master, 'testing');

        self::assertSame($master->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC), $resolved);
        self::assertNotSame($raw, $resolved);
        self::assertNotSame(" \t\n", $resolved);
    }

    #[Test]
    public function derived_bytes_differ_from_the_master_and_every_other_purpose(): void
    {
        $raw = random_bytes(32);
        $master = ApplicationSecret::fromEnvironmentValue('base64:' . base64_encode($raw), 'production');
        $auth = AuthTokenSecret::resolve(null, $master, 'production');
        $others = [
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
            ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC,
            ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC,
        ];

        self::assertNotSame($raw, $auth);
        self::assertSame(32, strlen($auth));
        foreach ($others as $purpose) {
            self::assertNotSame($master->derive($purpose), $auth, $purpose);
        }
    }

    #[Test]
    public function missing_custody_is_refused_even_in_local(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('application-secret custody');
        AuthTokenSecret::resolve(null, null, 'local');
    }

    #[Test]
    public function non_string_explicit_values_fail_without_echoing_the_payload(): void
    {
        try {
            AuthTokenSecret::resolve(12345, null, 'production');
            self::fail('Non-string explicit secret was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid', $exception->getMessage());
            self::assertStringNotContainsString('12345', $exception->getMessage());
        }
    }

    #[Test]
    public function invalid_explicit_exceptions_do_not_echo_the_secret(): void
    {
        $secret = 'Change-Me';
        try {
            AuthTokenSecret::resolve($secret, null, 'local');
            self::fail('Invalid explicit secret was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString('Change-Me', $exception->getMessage());
        }
    }

    #[Test]
    public function unusable_environment_labels_are_not_echoed_into_the_exception(): void
    {
        try {
            AuthTokenSecret::resolve('too-short', null, '!!!');
            self::fail('A short explicit secret was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unspecified', $exception->getMessage());
            self::assertStringNotContainsString('!!!', $exception->getMessage());
            self::assertStringNotContainsString('too-short', $exception->getMessage());
        }
    }

    // ------------------------------------------- source classification (#2500)

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function absentConfigurations(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'spaces' => ['   '];
        yield 'tab and newline' => ["\t\n"];
    }

    #[Test]
    #[DataProvider('absentConfigurations')]
    public function absent_or_empty_configuration_is_classified_as_derived_custody(mixed $configured): void
    {
        self::assertTrue(AuthTokenSecret::usesDerivedCustody($configured, 'production'));
    }

    #[Test]
    public function a_valid_explicit_secret_is_not_derived_custody(): void
    {
        self::assertFalse(
            AuthTokenSecret::usesDerivedCustody('abcdefghijklmnopqrstuvwxyz012345', 'production'),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExplicitValues(): iterable
    {
        yield 'too short' => ['short'];
        yield 'one under the floor' => [str_repeat('a', 31)];
        yield 'placeholder' => ['changeme'];
        yield 'folded placeholder' => ['CHANGE-ME'];
        yield 'padded placeholder' => ['  change me  '];
        yield 'integer' => [42];
        yield 'array' => [[]];
        yield 'bool' => [false];
        yield 'object' => [new \stdClass()];
    }

    #[Test]
    #[DataProvider('invalidExplicitValues')]
    public function invalid_explicit_input_throws_and_is_never_classified_as_absent(mixed $configured): void
    {
        // Classifying a rejected secret as "absent" would be fail-open twice over: it
        // would derive a key from the application master AND enrol the auth package in
        // master rotation on the strength of a value the operator got wrong.
        $this->expectException(\RuntimeException::class);
        AuthTokenSecret::usesDerivedCustody($configured, 'production');
    }

    #[Test]
    #[DataProvider('invalidExplicitValues')]
    public function resolve_and_classification_refuse_invalid_input_identically(mixed $configured): void
    {
        $classifyFailed = false;
        $resolveFailed = false;
        try {
            AuthTokenSecret::usesDerivedCustody($configured, 'production');
        } catch (\RuntimeException) {
            $classifyFailed = true;
        }
        try {
            AuthTokenSecret::resolve($configured, null, 'production');
        } catch (\RuntimeException) {
            $resolveFailed = true;
        }

        // The two entry points must never disagree, or the token binding and the rekey
        // contribution could take different branches for one configuration.
        self::assertTrue($classifyFailed && $resolveFailed);
    }

    #[Test]
    public function classification_agrees_with_resolution_across_every_valid_shape(): void
    {
        $master = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(str_repeat("\x53", 32)),
            'testing',
        );
        $explicit = 'abcdefghijklmnopqrstuvwxyz012345';

        foreach ([null, '', '   ', $explicit, '  ' . $explicit . '  '] as $configured) {
            $derived = AuthTokenSecret::usesDerivedCustody($configured, 'testing');
            $resolved = AuthTokenSecret::resolve($configured, $master, 'testing');
            $isMasterDerived = hash_equals(
                $master->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
                $resolved,
            );

            self::assertSame(
                $derived,
                $isMasterDerived,
                'classification must match what resolve() actually returns',
            );
        }
    }

    #[Test]
    public function derived_custody_still_requires_application_secret_custody(): void
    {
        // The app_secret fallback stays removed: with no custody there is no key, and
        // the failure is loud rather than a weaker substitute.
        self::assertTrue(AuthTokenSecret::usesDerivedCustody(null, 'production'));
        $this->expectException(\RuntimeException::class);
        AuthTokenSecret::resolve(null, null, 'production');
    }
}
