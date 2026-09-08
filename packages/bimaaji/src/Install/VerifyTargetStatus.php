<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * One per-target informational status emitted on successful verification.
 */
final readonly class VerifyTargetStatus
{
    public function __construct(
        public string $clientId,
        public string $path,
        public string $wholefile,
        public string $managedRegion,
    ) {}

    /**
     * @return array{client: string, path: string, wholefile: string, managed_region: string}
     */
    public function toArray(): array
    {
        return [
            'client' => $this->clientId,
            'path' => $this->path,
            'wholefile' => $this->wholefile,
            'managed_region' => $this->managedRegion,
        ];
    }
}
