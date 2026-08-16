<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * Roll-up of {@see VerifyRunner::verify()} outcomes used by the
 * formatter and the CLI exit-code logic.
 *
 * Strict S1 verification exits non-zero for every mismatch, unknown, orphan,
 * or schema-authority mismatch. An unverifiable historical row is evidence
 * debt, not a successful enterprise verification.
 * @api
 */
final readonly class VerifySummary
{
    public function __construct(
        public int $match,
        public int $mismatch,
        public int $unknown,
        public int $orphan,
        public int $authorityMismatch,
    ) {}

    public function hasFailure(): bool
    {
        return $this->mismatch > 0
            || $this->unknown > 0
            || $this->orphan > 0
            || $this->authorityMismatch > 0;
    }

    public function total(): int
    {
        return $this->match + $this->mismatch + $this->unknown + $this->orphan + $this->authorityMismatch;
    }
}
