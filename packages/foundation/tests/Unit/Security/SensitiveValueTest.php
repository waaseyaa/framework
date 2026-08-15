<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversClass(SensitiveValue::class)]
final class SensitiveValueTest extends TestCase
{
    #[Test]
    public function ordinary_views_never_disclose_secret_bytes(): void
    {
        $canary = 'cfg04-sensitive-value-canary';
        $value = SensitiveValue::fromBytes($canary, SecretClass::IntegrationCredential, 'synthetic-v1');

        $this->assertStringNotContainsString($canary, var_export($value, true));
        $this->assertStringNotContainsString($canary, print_r($value, true));
        $this->assertStringNotContainsString($canary, json_encode($value, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function cast_serialization_and_clone_are_refused(): void
    {
        $value = SensitiveValue::fromBytes(
            'cfg04-sensitive-value-refusal',
            SecretClass::ProviderCredential,
            'synthetic-v1',
        );

        try {
            (string) $value;
            $this->fail('SensitiveValue string cast must fail.');
        } catch (\LogicException) {
        }

        try {
            serialize($value);
            $this->fail('SensitiveValue serialization must fail.');
        } catch (\LogicException) {
        }

        $reflection = new \ReflectionClass($value);
        $this->assertFalse($reflection->isCloneable());
    }
}
