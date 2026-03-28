<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class ProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> */
    private array $providers = [];

    public function register(string $name, OAuthProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function get(string $name): OAuthProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new \InvalidArgumentException(sprintf('OAuth provider "%s" is not registered.', $name));
        }

        return $this->providers[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @return array<string, OAuthProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
