<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\EntityBase;

/**
 * Minimal revisionable-shaped entity for exercising the entity tools without a
 * real storage stack. Carries values in an array and records the revision log
 * the tools set, so tests can assert on `revision_log` handling.
 */
final class ToolTestEntity extends EntityBase
{
    private string $revisionLog = '';

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'tool_test', [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'revision' => 'revision_id',
        ]);
        $this->enforceIsNew(false);
    }

    // Intentionally no getValues(): the read/search tools must fall back to the
    // EntityInterface-guaranteed toArray() so they work for every entity.

    // Revisionable surface the tools probe via method_exists().

    public function setRevisionLog(string $log): static
    {
        $this->revisionLog = $log;

        return $this;
    }

    public function getRevisionLog(): string
    {
        return $this->revisionLog;
    }

    public function getRevisionId(): ?int
    {
        $rid = $this->get('revision_id');

        return is_int($rid) ? $rid : null;
    }

    public function isCurrentRevision(): bool
    {
        return (bool) $this->get('is_current');
    }
}
