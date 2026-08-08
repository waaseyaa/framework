<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/** @api */
final readonly class TableInstallEvidence implements \JsonSerializable
{
    public function __construct(
        public RuntimeTablePolicy $policy,
        public int $beforeRows,
        public string $beforeDigest,
        public int $afterRows,
        public string $afterDigest,
    ) {}

    /** @return array{policy:string,before_rows:int,before_digest:string,after_rows:int,after_digest:string} */
    public function jsonSerialize(): array
    {
        return [
            'policy' => $this->policy->value,
            'before_rows' => $this->beforeRows,
            'before_digest' => $this->beforeDigest,
            'after_rows' => $this->afterRows,
            'after_digest' => $this->afterDigest,
        ];
    }
}
