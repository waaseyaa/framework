<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use DateTimeInterface;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Field\FieldStorage;

/**
 * Defines the media content entity.
 *
 * Media entities represent pieces of media content (images, videos, files, etc.)
 * that can be reused across the site. Each media entity belongs to a media type
 * (bundle) which determines the source plugin and field configuration.
 */
#[ContentEntityType(id: 'media', label: 'Media', description: 'Uploaded files, images, and embedded media', api: true)]
#[ContentEntityKeys(id: 'mid', uuid: 'uuid', label: 'name', bundle: 'bundle')]
final class Media extends ContentEntityBase
{
    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        'created' => ['type' => 'datetime_immutable', 'storage' => 'unix'],
        'changed' => ['type' => 'datetime_immutable', 'storage' => 'unix'],
    ];

    #[Field(label: 'Name', description: 'The display name of this media item.', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $name = '';

    #[Field(label: 'Media type', description: 'The bundle (media type) of this item.', required: true, readOnly: true, settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $bundle = '';

    /**
     * Canonical URI returned by the media upload endpoint.
     *
     * A media upload returns a URI string rather than an embedded file metadata
     * object, so this remains a string field with an explicit file-widget hint.
     * Bytes and sidecar metadata continue to be owned by the existing upload
     * path; this declaration only makes that path reachable to generic schema
     * authoring and does not activate the parked media-version subsystem.
     */
    #[Field(
        type: 'string',
        label: 'File',
        description: 'The uploaded file for this media item.',
        settings: ['widget' => 'file', 'weight' => 5],
        stored: FieldStorage::Data,
        read: \Waaseyaa\Entity\FieldReadLevel::Protected,
    )]
    public ?string $source_uri = null;

    #[Field(type: 'integer', required: false, label: 'Owner', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $uid = null;

    #[Field(type: 'boolean', required: false, label: 'Published', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public bool $status = true;

    #[Field(type: 'integer', required: false, label: 'Created', settings: ['subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public ?int $created = null;

    #[Field(type: 'integer', required: false, label: 'Changed', settings: ['subtype' => 'timestamp'], read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public ?int $changed = null;

    /**
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Ensure a default for the optional published-status property. Media is
        // published-by-default (see isPublished()); backfilling here — mirroring
        // Term's and Node's constructors — makes the entity class itself the
        // single canonical source of that default, instead of leaving a missing
        // 'status' key for every downstream consumer (e.g. WorkflowVisibility) to
        // reinterpret independently.
        if (!array_key_exists('status', $values)) {
            $values['status'] = true;
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Gets the media name.
     */
    public function getName(): string
    {
        return (string) ($this->get('name') ?? '');
    }

    /**
     * Sets the media name.
     */
    public function setName(string $name): static
    {
        $this->set('name', $name);

        return $this;
    }

    /**
     * Gets the media type (bundle) machine name.
     */
    public function getBundle(): string
    {
        return $this->bundle();
    }

    /**
     * Gets the owner (author) user ID.
     */
    public function getOwnerId(): ?int
    {
        $ownerId = $this->get('uid');

        return $ownerId !== null ? (int) $ownerId : null;
    }

    /**
     * Sets the owner (author) user ID.
     */
    public function setOwnerId(int $ownerId): static
    {
        $this->set('uid', $ownerId);

        return $this;
    }

    /**
     * Returns whether this media entity is published.
     */
    public function isPublished(): bool
    {
        return (bool) ($this->get('status') ?? true);
    }

    /**
     * Sets the published status.
     */
    public function setPublished(bool $published): static
    {
        $this->set('status', $published);

        return $this;
    }

    /**
     * Gets the creation timestamp.
     */
    public function getCreatedTime(): ?int
    {
        $created = $this->get('created');
        if ($created === null) {
            return null;
        }
        if ($created instanceof DateTimeInterface) {
            return $created->getTimestamp();
        }

        return (int) $created;
    }

    /**
     * Sets the creation timestamp.
     */
    public function setCreatedTime(int $timestamp): static
    {
        $this->set('created', $timestamp);

        return $this;
    }

    /**
     * Gets the last changed timestamp.
     */
    public function getChangedTime(): ?int
    {
        $changed = $this->get('changed');
        if ($changed === null) {
            return null;
        }
        if ($changed instanceof DateTimeInterface) {
            return $changed->getTimestamp();
        }

        return (int) $changed;
    }

    /**
     * Sets the last changed timestamp.
     */
    public function setChangedTime(int $timestamp): static
    {
        $this->set('changed', $timestamp);

        return $this;
    }
}
