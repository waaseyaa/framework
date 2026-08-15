<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;

#[CoversClass(SecretReference::class)]
final class SecretReferenceTest extends TestCase
{
    #[Test]
    public function serializes_closed_non_secret_reference_fields_and_has_a_stable_fingerprint(): void
    {
        $reference = SecretReference::create(
            provider: 'synthetic-vault',
            identifier: 'tenant/example/provider-key',
            secretClass: SecretClass::ProviderCredential,
            purpose: 'waaseyaa.ai.embedding.v1',
        );

        $this->assertSame([
            'provider' => 'synthetic-vault',
            'identifier' => 'tenant/example/provider-key',
            'secret_class' => 'provider-credential',
            'purpose' => 'waaseyaa.ai.embedding.v1',
        ], $reference->toArray());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $reference->fingerprint());
        $this->assertSame($reference->fingerprint(), SecretReference::create(
            'synthetic-vault',
            'tenant/example/provider-key',
            SecretClass::ProviderCredential,
            'waaseyaa.ai.embedding.v1',
        )->fingerprint());
    }

    #[Test]
    public function debug_view_discloses_only_fingerprint_class_and_purpose(): void
    {
        $reference = SecretReference::create(
            'synthetic-vault',
            'tenant/example/private/provider/path',
            SecretClass::ProviderCredential,
            'waaseyaa.ai.embedding.v1',
        );

        ob_start();
        var_dump($reference);
        $debug = (string) ob_get_clean();

        $this->assertStringContainsString($reference->fingerprint(), $debug);
        $this->assertStringNotContainsString('tenant/example/private/provider/path', $debug);
        $this->assertStringNotContainsString('synthetic-vault', $debug);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReferences')]
    public function rejects_malformed_reference_fields(string $provider, string $identifier, string $purpose): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecretReference::create($provider, $identifier, SecretClass::IntegrationCredential, $purpose);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidReferences(): iterable
    {
        yield 'provider path' => ['../../vault', 'valid/id', 'waaseyaa.mcp.auth.v1'];
        yield 'empty identifier' => ['vault', '', 'waaseyaa.mcp.auth.v1'];
        yield 'control byte' => ['vault', "bad\nidentifier", 'waaseyaa.mcp.auth.v1'];
        yield 'unversioned purpose' => ['vault', 'valid/id', 'waaseyaa.mcp.auth'];
    }
}
