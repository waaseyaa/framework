<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * A saved Wayfinding trail (Phase 4): an ordered, element-anchored beacon list
 * persisted as a versioned, translatable content entity (LD-5 / FR-009).
 *
 * Two-axis storage (revisionable + translatable, en + fr): each language is a
 * peer with its own current value and its own independent revision history. The
 * peer row holds the *live* trail (what a human authors and what plays back);
 * the per-language revision history holds prior versions and re-recorded
 * **drafts**. That split is the substrate for the no-silent-overwrite rule
 * (FR-011): re-recording a human-owned trail appends a draft revision and never
 * touches the live peer row — see {@see \Waaseyaa\Wayfinding\Trail\TrailStore}.
 *
 * `beacons` is a JSON-encoded ordered list of `{anchor_id, content, order}`;
 * {@see TrailStore} owns (de)serialization so the column stays a plain string.
 * `origin` records whether the live value came from an agent recording
 * (`recorded`) or a human edit (`human`) — the latch the re-record rule reads.
 *
 * @api
 */
#[ContentEntityType(id: 'wayfinding_trail', label: 'Wayfinding Trail', description: 'A saved, versioned, translatable guided trail of beacons.')]
#[ContentEntityKeys(
    id: 'tid',
    uuid: 'uuid',
    label: 'title',
    revision: 'revision_id',
    langcode: 'langcode',
    default_langcode: 'default_langcode',
)]
final class Trail extends ContentEntityBase
{
    /** The live value originated from a human edit and must not be overwritten by a re-record. */
    public const string ORIGIN_HUMAN = 'human';

    /** The live value originated from an agent recording (safe to advance on re-record). */
    public const string ORIGIN_RECORDED = 'recorded';

    #[Field(label: 'Title', description: 'Human-readable trail title.', required: true, settings: ['weight' => 0])]
    public string $title = '';

    #[Field(type: 'text', label: 'Beacons', description: 'JSON-encoded ordered list of {anchor_id, content, order}.', required: true, settings: ['weight' => 1])]
    public string $beacons = '[]';

    #[Field(type: 'integer', label: 'Owner UID', description: 'Account that owns this saved trail.', required: true, settings: ['weight' => 2, 'not_null' => true])]
    public int $owner_uid = 0;

    #[Field(label: 'Origin', description: 'Provenance of the live value: "recorded" or "human".', required: true, settings: ['weight' => 3])]
    public string $origin = self::ORIGIN_RECORDED;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
