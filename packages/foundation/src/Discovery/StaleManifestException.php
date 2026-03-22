<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

final class StaleManifestException extends \RuntimeException
{
    /**
     * @param list<string> $missingProviders
     */
    public function __construct(
        private readonly array $missingProviders,
        private readonly string $manifestPath,
        private readonly string $recoveryCommand = 'php bin/waaseyaa optimize:manifest',
    ) {
        parent::__construct(sprintf(
            'Package manifest is stale. Missing provider classes: %s. Manifest: %s. Run: %s',
            implode(', ', $this->missingProviders),
            $this->manifestPath,
            $this->recoveryCommand,
        ));
    }

    /**
     * @return list<string>
     */
    public function missingProviders(): array
    {
        return $this->missingProviders;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function recoveryCommand(): string
    {
        return $this->recoveryCommand;
    }
}
