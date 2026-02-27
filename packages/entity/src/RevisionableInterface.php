<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface RevisionableInterface
{
    public function getRevisionId(): int|string|null;

    public function isDefaultRevision(): bool;

    public function isLatestRevision(): bool;
}
