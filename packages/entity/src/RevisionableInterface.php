<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

interface RevisionableInterface
{
    public function getRevisionId(): ?int;

    public function isDefaultRevision(): bool;

    public function isLatestRevision(): bool;

    public function setNewRevision(bool $value): void;

    /** @return bool|null null means "use entity type default" */
    public function isNewRevision(): ?bool;

    public function setRevisionLog(?string $log): void;

    public function getRevisionLog(): ?string;
}
