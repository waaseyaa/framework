<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** Opaque pointer emitted by the protected search index. @api */
final readonly class SearchCandidateReference
{
    public function __construct(
        public string $documentId,
        public string $entityType,
    ) {
        if ($documentId === '') {
            throw new \InvalidArgumentException('Search candidate document id cannot be empty.');
        }
    }

    public function namespace(): ?string
    {
        $separator = strpos($this->documentId, ':');
        if ($separator === false || $separator === 0) {
            return null;
        }

        return substr($this->documentId, 0, $separator);
    }
}
