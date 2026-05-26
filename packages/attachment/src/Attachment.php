<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * The Attachment content entity.
 *
 * Represents a file attachment linked to a parent entity. Enforces the
 * at-most-one-active invariant via {@see AttachmentRepository::setActive()}.
 *
 * Fields stored in the _data JSON blob: filename, content_type, size,
 * storage_uri, checksum, ai_accessible.
 */
#[ContentEntityType(id: 'attachment', label: 'Attachment', description: 'File attachment linked to a parent entity.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'filename')]
final class Attachment extends ContentEntityBase
{
    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via duplicateInstance().
     * @param array<string, mixed> $fieldDefinitions Field definitions (injected by EntityInstantiator).
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = 'attachment',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'filename'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Gets the AI accessibility setting for this attachment.
     *
     * Returns one of: 'yes', 'no', 'inherit' (default).
     *
     * - 'yes'     — AI tools may read this file.
     * - 'no'      — AI tools may not read this file.
     * - 'inherit' — Defer to the entity's classification label.
     *               Until M-A4 ships, 'inherit' resolves to 'yes' for
     *               unclassified entities (access-preserving default, C-004).
     *
     * @return 'yes'|'no'|'inherit'
     */
    public function getAiAccessible(): string
    {
        $value = $this->get('ai_accessible');

        if ($value === 'yes' || $value === 'no') {
            return $value;
        }

        return 'inherit';
    }

    /**
     * Sets the AI accessibility setting for this attachment.
     *
     * @param 'yes'|'no'|'inherit' $value
     */
    public function setAiAccessible(string $value): static
    {
        if (!in_array($value, ['yes', 'no', 'inherit'], strict: true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid AI accessibility value "%s". Must be one of: yes, no, inherit.', $value),
            );
        }

        $this->set('ai_accessible', $value);

        return $this;
    }
}
