<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Immutable identity shared by every configuration-aware consumer. @api */
final readonly class ConfigurationAuthorityContext
{
    /**
     * @param list<string> $selectorProvenance
     */
    public function __construct(
        public string $authorityId,
        public string $databaseIdentity,
        public string $syncPath,
        public array $selectorProvenance,
        public ?string $activeGenerationId = null,
        public ?int $activationSequence = null,
    ) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $authorityId)) {
            throw new \InvalidArgumentException('Configuration authority id must be a lowercase SHA-256 digest.');
        }
        if ($databaseIdentity === '' || $syncPath === '' || $selectorProvenance === []) {
            throw new \InvalidArgumentException('Configuration authority identity fields must not be empty.');
        }
        if (($activeGenerationId === null) !== ($activationSequence === null)) {
            throw new \InvalidArgumentException('Active generation id and activation sequence must be present together.');
        }
        if ($activationSequence !== null && $activationSequence < 1) {
            throw new \InvalidArgumentException('Activation sequence must be positive.');
        }
    }

    public function usedLegacySelector(): bool
    {
        return in_array('config_dir', $this->selectorProvenance, true)
            || in_array('WAASEYAA_CONFIG_DIR', $this->selectorProvenance, true);
    }

    public function requireActiveGenerationId(): string
    {
        return $this->activeGenerationId
            ?? throw new ConfigurationAuthorityUnavailableException(
                'Active configuration generation is unavailable; apply the CFG-02 activation migration and bind its authority.',
            );
    }
}
