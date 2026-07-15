<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Security\ApplicationSecret;

#[CoversClass(ApplicationSecret::class)]
final class ApplicationSecretTest extends TestCase
{
    private const string RAW_SECRET = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function derives_stable_distinct_32_byte_keys_for_versioned_purposes(): void
    {
        $secret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(self::RAW_SECRET),
            'production',
        );

        $audit = $secret->derive('waaseyaa.audit.checkpoint-hmac.v1');
        $cache = $secret->derive('waaseyaa.cache.payload-hmac.v1');

        self::assertSame(32, strlen($audit));
        self::assertSame('e23e4d27a298049796e8130365c0c604165c1677996b91e6b9af8abe264f5674', bin2hex($audit));
        self::assertSame('7591a9a6f363da34a9cca82a3bb670bb50e9f140d3b4a2c99cb713edffccb8bb', bin2hex($cache));
        self::assertSame($audit, $secret->derive('waaseyaa.audit.checkpoint-hmac.v1'));
        self::assertNotSame($audit, $cache);
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidProductionSecrets(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'published placeholder' => ['change-me'];
        yield 'missing base64 prefix' => [base64_encode(self::RAW_SECRET)];
        yield 'malformed base64' => ['base64:not-base64!'];
        yield 'noncanonical base64' => ['base64:' . rtrim(base64_encode(self::RAW_SECRET), '=')];
        yield 'wrong decoded length' => ['base64:' . base64_encode('too short')];
    }

    #[Test]
    #[DataProvider('invalidProductionSecrets')]
    public function rejects_invalid_secrets_outside_development(?string $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WAASEYAA_APP_SECRET');

        ApplicationSecret::fromEnvironmentValue($value, 'staging');
    }

    #[Test]
    public function development_fallback_is_ephemeral_per_kernel_instance(): void
    {
        $first = ApplicationSecret::fromEnvironmentValue(null, 'testing');
        $second = ApplicationSecret::fromEnvironmentValue(null, 'testing');

        self::assertNotSame(
            $first->derive('waaseyaa.audit.checkpoint-hmac.v1'),
            $second->derive('waaseyaa.audit.checkpoint-hmac.v1'),
        );
    }

    #[Test]
    public function invalid_secret_bytes_never_appear_in_the_configuration_error(): void
    {
        $invalid = 'base64:operator-secret-that-must-not-leak';

        try {
            ApplicationSecret::fromEnvironmentValue($invalid, 'production');
            self::fail('Invalid application secret must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($invalid, $e->getMessage());
            self::assertStringNotContainsString('operator-secret', $e->getMessage());
        }
    }

    #[Test]
    public function master_and_derived_bytes_are_redacted_and_cannot_be_serialized(): void
    {
        $secret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(self::RAW_SECRET),
            'production',
        );
        $derived = $secret->derive('waaseyaa.audit.checkpoint-hmac.v1');

        ob_start();
        var_dump($secret);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString(self::RAW_SECRET, $debug);
        self::assertStringNotContainsString($derived, $debug);
        self::assertStringContainsString('[REDACTED]', $debug);

        try {
            serialize($secret);
            self::fail('ApplicationSecret serialization must fail closed.');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString(self::RAW_SECRET, $e->getMessage());
            self::assertStringNotContainsString($derived, $e->getMessage());
        }
    }
}
