<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Sovereignty;

final class SovereigntyConfig implements SovereigntyConfigInterface
{
    /** @var array<string, string> */
    private readonly array $effective;

    /** @param array<string, string> $overrides */
    public function __construct(
        private readonly SovereigntyProfile $profile,
        array $overrides,
    ) {
        $this->effective = array_merge(
            SovereigntyDefaults::for($this->profile),
            array_filter($overrides, is_string(...)),
        );
    }

    public function get(string $key): ?string
    {
        return $this->effective[$key] ?? null;
    }

    public function getProfile(): SovereigntyProfile
    {
        return $this->profile;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->effective;
    }

    /**
     * Create from an app config array (e.g., config/waaseyaa.php contents).
     *
     * Reads 'sovereignty_profile' for the profile name, defaults to 'local'.
     * All other recognized keys are treated as overrides.
     *
     * @param array<string, mixed> $appConfig
     */
    public static function fromArray(array $appConfig): self
    {
        $profileName = (string) ($appConfig['sovereignty_profile'] ?? 'local');
        $profile = SovereigntyProfile::tryFrom($profileName) ?? SovereigntyProfile::Local;

        $knownKeys = ['storage', 'embeddings', 'llm_provider', 'transcriber', 'vector_store', 'queue_backend'];
        $overrides = [];
        foreach ($knownKeys as $key) {
            if (isset($appConfig[$key]) && is_string($appConfig[$key])) {
                $overrides[$key] = $appConfig[$key];
            }
        }

        return new self($profile, $overrides);
    }
}
