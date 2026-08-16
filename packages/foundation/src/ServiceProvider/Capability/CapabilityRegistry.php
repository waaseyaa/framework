<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Validates provider capabilities once after registration and before boot. */
final class CapabilityRegistry
{
    /** @param list<ServiceProvider> $providers */
    public function validate(array $providers): void
    {
        /** @var array<string, array{declaration: CapabilityDeclaration, provider: class-string}> $available */
        $available = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof ProvidesCapabilitiesInterface) {
                continue;
            }
            foreach ($provider->capabilityDeclarations() as $declaration) {
                $existing = $available[$declaration->id] ?? null;
                if ($existing !== null
                    && ($existing['declaration']->version !== $declaration->version
                        || $existing['declaration']->authorityFingerprint !== $declaration->authorityFingerprint)
                ) {
                    throw new RequiredCapabilityUnavailableException(sprintf(
                        'Capability "%s" has divergent providers %s and %s.',
                        $declaration->id,
                        $existing['provider'],
                        $provider::class,
                    ));
                }
                $available[$declaration->id] = [
                    'declaration' => $declaration,
                    'provider' => $provider::class,
                ];
            }
        }

        foreach ($providers as $provider) {
            if (!$provider instanceof RequiresCapabilitiesInterface) {
                continue;
            }
            foreach ($provider->capabilityRequirements() as $requirement) {
                $declaration = $available[$requirement->id]['declaration'] ?? null;
                if (!$declaration instanceof CapabilityDeclaration || !$requirement->accepts($declaration)) {
                    throw new RequiredCapabilityUnavailableException(sprintf(
                        'Provider %s requires capability "%s" version %d..%d, but no compatible authority is registered.',
                        $provider::class,
                        $requirement->id,
                        $requirement->minimumVersion,
                        $requirement->maximumVersion,
                    ));
                }
            }
        }
    }
}
