<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

final class ProviderDiscovery
{
    /**
     * Discover provider class names from Composer's installed.json data.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function discoverFromArray(array $installed): array
    {
        $providers = [];

        foreach ($installed['packages'] ?? [] as $package) {
            $auroraExtra = $package['extra']['aurora'] ?? null;
            if ($auroraExtra === null) {
                continue;
            }

            foreach ($auroraExtra['providers'] ?? [] as $providerClass) {
                $providers[] = $providerClass;
            }
        }

        return $providers;
    }

    /**
     * Discover providers from the vendor directory's installed.json.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function discoverFromVendor(string $vendorPath): array
    {
        $installedPath = $vendorPath . '/composer/installed.json';
        if (!is_file($installedPath)) {
            return [];
        }

        $installed = json_decode(file_get_contents($installedPath), true);

        return $this->discoverFromArray($installed);
    }
}
