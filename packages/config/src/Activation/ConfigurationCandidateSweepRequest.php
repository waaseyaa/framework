<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/** Lease-bound cutoff for staged-candidate lifecycle maintenance. @api */
final readonly class ConfigurationCandidateSweepRequest
{
    public function __construct(
        public string $requestId,
        public string $leaseDomain,
        public int $fence,
        public \DateTimeImmutable $createdBefore,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Candidate sweep request ID is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $leaseDomain) !== 1 || $fence < 1) {
            throw new \InvalidArgumentException('Candidate sweep requires a stable lease domain and positive fence.');
        }
    }
}
