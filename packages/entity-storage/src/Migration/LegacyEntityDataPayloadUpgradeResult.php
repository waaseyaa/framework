<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Migration;

/** @api */
final readonly class LegacyEntityDataPayloadUpgradeResult
{
    /** @param array<string, int> $changedByTable */
    public function __construct(
        public int $scannedRows,
        public int $changedRows,
        public array $changedByTable,
    ) {}
}
