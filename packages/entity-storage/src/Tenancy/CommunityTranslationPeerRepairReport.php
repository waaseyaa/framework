<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tenancy;

/** Result of an explicit empty-discriminator translation-peer repair. @api */
final readonly class CommunityTranslationPeerRepairReport
{
    public function __construct(
        public int $examined,
        public int $eligible,
        public int $repaired,
        public int $skipped,
        public bool $dryRun,
    ) {}

    /** @return array{examined: int, eligible: int, repaired: int, skipped: int, dry_run: bool} */
    public function toArray(): array
    {
        return [
            'examined' => $this->examined,
            'eligible' => $this->eligible,
            'repaired' => $this->repaired,
            'skipped' => $this->skipped,
            'dry_run' => $this->dryRun,
        ];
    }
}
