<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

final class InvalidEmbeddingIndexPolicyException extends \InvalidArgumentException
{
    public function __construct(string $reason)
    {
        parent::__construct('[AIV-POLICY-001] Invalid embedding index policy: ' . $reason);
    }
}
