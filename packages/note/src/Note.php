<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Represents a built-in Note entity (core.note).
 *
 * A Note is the minimal default content type shipped with Waaseyaa.
 * The built-in type definition is immutable; individual note rows are
 * deletable when NoteAccessPolicy grants row-level access.
 */
#[ContentEntityType(id: 'note', label: 'Note', description: 'Quick-entry content items with minimal structure', api: true)]
#[ContentEntityKeys(label: 'title')]
final class Note extends ContentEntityBase
{
    #[Field(label: 'Title', description: 'Note title.', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'text', label: 'Body', description: 'Note body. Plain text or Markdown.', required: false, settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public ?string $body = null;

    #[Field(type: 'entity_reference', label: 'Author', required: false, settings: ['target_type' => 'user', 'weight' => 2, 'authorizationInput' => true], read: FieldReadLevel::Protected)]
    public ?int $uid = null;

    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    public function setTitle(string $title): static
    {
        $this->set('title', $title);

        return $this;
    }

    public function getBody(): string
    {
        return (string) ($this->get('body') ?? '');
    }
}
