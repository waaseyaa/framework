<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Implements RevisionableInterface for entity classes.
 *
 * Requires the using class to extend EntityBase (needs $values and $entityKeys).
 */
trait RevisionableEntityTrait
{
    /** @var bool|null null = use entity type default */
    private ?bool $newRevision = null;

    public function getRevisionId(): ?int
    {
        $revisionKey = $this->entityKeys['revision'] ?? 'revision_id';
        $value = $this->values[$revisionKey] ?? null;

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function isDefaultRevision(): bool
    {
        return (bool) ($this->values['is_default_revision'] ?? true);
    }

    public function isLatestRevision(): bool
    {
        return (bool) ($this->values['is_latest_revision'] ?? true);
    }

    public function setNewRevision(bool $value): void
    {
        $this->newRevision = $value;
    }

    public function isNewRevision(): ?bool
    {
        return $this->newRevision;
    }

    public function setRevisionLog(?string $log): void
    {
        $this->values['revision_log'] = $log;
    }

    public function getRevisionLog(): ?string
    {
        return isset($this->values['revision_log'])
            ? (string) $this->values['revision_log']
            : null;
    }
}
