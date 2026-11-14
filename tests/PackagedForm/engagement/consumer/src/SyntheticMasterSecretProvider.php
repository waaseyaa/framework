<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Process-only synthetic master source for this isolated consumer proof. */
final class SyntheticMasterSecretProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'engagement-fixture-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        if ($reference->secretClass() !== SecretClass::ApplicationMaster) {
            throw new \RuntimeException('Synthetic fixture secret provider only serves application-master material.');
        }
        $encoded = getenv('WAASEYAA_APP_SECRET');
        $bytes = is_string($encoded) && str_starts_with($encoded, 'base64:')
            ? base64_decode(substr($encoded, 7), true)
            : false;
        if (!is_string($bytes) || strlen($bytes) !== 32) {
            throw new \RuntimeException('Synthetic fixture master is unavailable.');
        }
        return SensitiveValue::fromBytes($bytes, SecretClass::ApplicationMaster, 'fixture-v1');
    }
}
