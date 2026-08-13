<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Capability;

/** @api */
final readonly class CapabilityDeclaration
{
    /**
     * @param list<string> $publicRoutes
     * @param list<string> $lifecycle
     * @param list<string> $verification
     */
    public function __construct(
        public string $id,
        public CapabilityState $state,
        public ?string $package = null,
        public ?string $provider = null,
        public ?string $configurationAuthority = null,
        public array $publicRoutes = [],
        public ?string $dataClassification = null,
        public array $lifecycle = [],
        public array $verification = [],
        public ?string $note = null,
        public ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = ['id' => $this->id, 'state' => $this->state->value];

        return match ($this->state) {
            CapabilityState::Active => $base + [
                'package' => $this->package,
                'provider' => $this->provider,
                'configuration_authority' => $this->configurationAuthority,
                'public_routes' => $this->publicRoutes,
                'data_classification' => $this->dataClassification,
                'lifecycle' => $this->lifecycle,
                'verification' => $this->verification,
            ],
            CapabilityState::Planned => $base + ['note' => $this->note],
            CapabilityState::NotNeeded => $base + ['reason' => $this->reason],
        };
    }
}
