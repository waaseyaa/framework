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
        public ?string $syncPathAnchor = null,
        public ?int $syncPathAnchorDevice = null,
        public ?int $syncPathAnchorInode = null,
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
        if ($activeGenerationId !== null && preg_match('/^[a-f0-9]{64}$/D', $activeGenerationId) !== 1) {
            throw new \InvalidArgumentException('Active configuration generation id must be a lowercase SHA-256 digest.');
        }
        if ($activationSequence !== null && $activationSequence < 1) {
            throw new \InvalidArgumentException('Activation sequence must be positive.');
        }
        $identityParts = [$syncPathAnchor, $syncPathAnchorDevice, $syncPathAnchorInode];
        $present = count(array_filter($identityParts, static fn(mixed $part): bool => $part !== null));
        if ($present !== 0 && $present !== 3) {
            throw new \InvalidArgumentException('Sync-path anchor identity must be entirely present or absent.');
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

    public function assertSyncPathIdentity(): void
    {
        if ($this->syncPathAnchor === null) {
            return;
        }
        $realAnchor = realpath($this->syncPathAnchor);
        $stat = $realAnchor === false ? false : @stat($realAnchor);
        if ($realAnchor === false || $stat === false
            || str_replace('\\', '/', $realAnchor) !== $this->syncPathAnchor
            || $stat['dev'] !== $this->syncPathAnchorDevice
            || $stat['ino'] !== $this->syncPathAnchorInode
        ) {
            throw new ConfigurationAuthorityConflictException(
                'Configuration sync-path anchor identity changed after authority resolution.',
            );
        }

        if (is_link($this->syncPath)) {
            throw new ConfigurationAuthorityConflictException('Configuration sync path became a symbolic link after authority resolution.');
        }
        if (file_exists($this->syncPath)) {
            $realSyncPath = realpath($this->syncPath);
            if ($realSyncPath === false || str_replace('\\', '/', $realSyncPath) !== $this->syncPath) {
                throw new ConfigurationAuthorityConflictException(
                    'Configuration sync path resolves to a different directory after authority resolution.',
                );
            }
        }
    }
}
