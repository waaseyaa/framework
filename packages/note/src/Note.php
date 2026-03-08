<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Represents a built-in Note entity (core.note).
 *
 * A Note is the minimal default content type shipped with Waaseyaa.
 * It is non-deletable via API — use NoteAccessPolicy to enforce that.
 */
final class Note extends ContentEntityBase
{
    protected string $entityTypeId = 'note';

    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    /**
     * @param array<string, mixed> $values Initial entity values.
     */
    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }

    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    public function setTitle(string $title): static
    {
        $this->set('title', $title);

        return $this;
    }

    public function getTenantId(): string
    {
        return (string) ($this->get('tenant_id') ?? '');
    }

    public function getBody(): string
    {
        return (string) ($this->get('body') ?? '');
    }
}
