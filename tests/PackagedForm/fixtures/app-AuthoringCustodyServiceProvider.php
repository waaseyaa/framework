<?php

declare(strict_types=1);

namespace App\Provider;

use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Signing custody for the packaged authoring host (#2430).
 *
 * Stands in for whatever a real authoring environment uses — a KMS, an agent, a
 * hardware token. What matters for the proof is the shape: the private key is
 * held by the authoring host and reaches the signer only through the secret
 * registry, and the importing consumer never installs this provider at all.
 *
 * Deliberately reads the key from a path OUTSIDE both project directories, so
 * "the consumer has no key material" is a property of the filesystem layout and
 * not merely of what the consumer chose to read.
 */
final class AuthoringCustodyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $registry = $this->kernelServices?->get(SecretResolverRegistry::class);
        if (!$registry instanceof SecretResolverRegistry) {
            throw new \RuntimeException('Authoring custody requires the kernel secret resolver registry.');
        }

        $registry->registerProvider(new FileSigningKeyProvider());
    }
}
