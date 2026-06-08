<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;

/**
 * Minimal revisionable-shaped entity for exercising the entity tools without a
 * real storage stack. Carries values in an array and records the revision log
 * the tools set, so tests can assert on `revision_log` handling.
 */
final class ToolTestEntity implements EntityInterface
{
    private string $revisionLog = '';
    private bool $new = false;

    /** @param array<string, mixed> $values */
    public function __construct(private array $values = []) {}

    public function enforceIsNew(): void
    {
        $this->new = true;
    }

    public function id(): int|string|null
    {
        return $this->values['id'] ?? null;
    }

    public function uuid(): string
    {
        return (string) ($this->values['uuid'] ?? '');
    }

    public function label(): string
    {
        return (string) ($this->values['title'] ?? ($this->values['name'] ?? ''));
    }

    public function getEntityTypeId(): string
    {
        return 'tool_test';
    }

    public function bundle(): string
    {
        return '';
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    // Intentionally no getValues(): the read/search tools must fall back to the
    // EntityInterface-guaranteed toArray() so they work for every entity.

    public function language(): string
    {
        return 'en';
    }

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
        $rid = $this->values['revision_id'] ?? null;

        return is_int($rid) ? $rid : null;
    }

    public function isCurrentRevision(): bool
    {
        return (bool) ($this->values['is_current'] ?? false);
    }
}
