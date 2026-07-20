<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityStructure;

/** Immutable candidate passed only to one contextual authorization invocation. */
final readonly class ContextualProtectedReadCandidate
{
    public function __construct(
        public string $key,
        public EntityStructure $structure,
        public PolicySubjectViewInterface $subject,
    ) {
        if ($key === '') {
            throw new \InvalidArgumentException('A contextual read candidate requires a non-empty key.');
        }
    }
}
