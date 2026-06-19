<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Trail;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Wayfinding\Entity\Trail;

/**
 * Persistence for saved Wayfinding trails (Phase 4): record-to-saved, human
 * authoring, and re-recording — on the `wayfinding_trail` two-axis
 * (revisionable + translatable) entity (LD-5 / FR-009..FR-011).
 *
 * The model maps one-to-one onto the framework's two-axis primitives:
 *
 *  - **Live / current value** = the per-language peer row, written by
 *    {@see EntityRepository::saveTranslation()} and read by
 *    {@see EntityRepository::loadTranslation()}. This is what plays back.
 *  - **History + drafts** = the per-language revision log, appended by
 *    {@see EntityRepository::saveTranslationRevision()} (which does NOT touch the
 *    peer row) and read by {@see EntityRepository::listTranslationRevisions()}.
 *
 * That split is the whole no-silent-overwrite rule (FR-011): re-recording onto a
 * **human-owned** trail appends a draft revision and leaves the live peer row —
 * the human's edits — exactly as it was. Languages (en + fr) sequence
 * independently, so editing one never disturbs another (FR-009).
 *
 * Requires the concrete {@see EntityRepository} (not the interface) because the
 * draft path uses the per-language revision API, which is part of the two-axis
 * surface beyond `EntityRepositoryInterface`.
 *
 * @api
 *
 * @phpstan-type BeaconShape array{anchor_id: string, content: string, order: int}
 */
final class TrailStore
{
    public function __construct(
        private readonly EntityRepository $trails,
    ) {}

    /**
     * FR-010: record a live trail to a new saved trail, owned by $ownerUid. The
     * recorded value is the live value of a fresh, agent-originated trail.
     *
     * @param list<BeaconShape|array<string, mixed>> $beacons
     */
    public function record(string $langcode, string $title, array $beacons, int $ownerUid): SavedTrail
    {
        $trail = new Trail(
            ['default_langcode' => 1] + $this->valuesFor($title, $beacons, Trail::ORIGIN_RECORDED, $ownerUid, $langcode),
        );
        $trail->enforceIsNew();
        $this->trails->save($trail);

        $id = (string) $trail->id();

        return $this->current($id, $langcode)
            ?? throw new \RuntimeException("Saved trail {$id} could not be read back after recording.");
    }

    /**
     * A human authors or edits a trail in one language. This advances the live
     * value and latches it `human` — so a later re-record will not overwrite it.
     * Creating a translation in a new language uses the same path; ownership is
     * inherited from the trail's default-language row.
     *
     * @param list<BeaconShape|array<string, mixed>> $beacons
     */
    public function editAsHuman(string $trailId, string $langcode, string $title, array $beacons): SavedTrail
    {
        $owner = $this->resolveOwner($trailId, $langcode);
        $this->trails->saveTranslation(
            $trailId,
            $langcode,
            $this->valuesFor($title, $beacons, Trail::ORIGIN_HUMAN, $owner, $langcode),
        );

        return $this->current($trailId, $langcode)
            ?? throw new \RuntimeException("Saved trail {$trailId} ({$langcode}) could not be read back after editing.");
    }

    /**
     * FR-011: re-record a live trail onto an existing saved trail.
     *
     * If the target language is human-owned, the re-recording is appended as a
     * **draft revision** and the live value is left untouched — never silently
     * overwritten. Otherwise (agent-recorded, or this language not yet
     * translated) it is safe to advance the live value, which is then promoted.
     * Either branch creates a new per-language revision.
     *
     * @param list<BeaconShape|array<string, mixed>> $beacons
     */
    public function reRecord(string $trailId, string $langcode, string $title, array $beacons): ReRecordResult
    {
        $current = $this->trails->loadTranslation($trailId, $langcode);
        $humanOwned = $current !== null && (string) $current->get('origin') === Trail::ORIGIN_HUMAN;

        $owner = $current !== null
            ? (int) $current->get('owner_uid')
            : $this->resolveOwner($trailId, null);
        $values = $this->valuesFor($title, $beacons, Trail::ORIGIN_RECORDED, $owner, $langcode);

        if ($humanOwned) {
            // Draft only: append a revision; the live peer row (human edits) is
            // not touched. The draft is recoverable from the revision history.
            $revisionId = $this->trails->saveTranslationRevision($trailId, $langcode, $values);

            return new ReRecordResult(promoted: false, revisionId: $revisionId);
        }

        // Nothing human to protect — advance the live value to the re-recording.
        $revisionId = $this->trails->saveTranslation($trailId, $langcode, $values);

        return new ReRecordResult(promoted: true, revisionId: $revisionId);
    }

    /**
     * The live (current) value of one language, or null when that language has
     * no saved trail.
     */
    public function current(string $trailId, string $langcode): ?SavedTrail
    {
        $entity = $this->trails->loadTranslation($trailId, $langcode);

        return $entity === null ? null : $this->toSavedTrail($trailId, $langcode, $entity);
    }

    /**
     * Number of revisions recorded for one language (history + drafts). A fresh
     * recording starts at zero per-language revisions; each edit/re-record adds
     * one.
     */
    public function revisionCount(string $trailId, string $langcode): int
    {
        return \count($this->trails->listTranslationRevisions($trailId, $langcode));
    }

    /**
     * The most recent revision snapshot for one language, or null when none —
     * used to surface a pending re-recorded draft for review.
     */
    public function latestRevision(string $trailId, string $langcode): ?SavedTrail
    {
        $revisions = $this->trails->listTranslationRevisions($trailId, $langcode);
        $latest = $revisions[0] ?? null; // listTranslationRevisions yields newest first

        return $latest === null ? null : $this->toSavedTrail($trailId, $langcode, $latest);
    }

    private function toSavedTrail(string $trailId, string $langcode, EntityInterface $entity): SavedTrail
    {
        return new SavedTrail(
            id: $trailId,
            langcode: $langcode,
            title: (string) ($entity->get('title') ?? ''),
            beacons: $this->decodeBeacons((string) ($entity->get('beacons') ?? '[]')),
            origin: (string) ($entity->get('origin') ?? Trail::ORIGIN_RECORDED),
            ownerUid: (int) ($entity->get('owner_uid') ?? 0),
        );
    }

    private function resolveOwner(string $trailId, ?string $langcode): int
    {
        if ($langcode !== null) {
            $peer = $this->trails->loadTranslation($trailId, $langcode);
            if ($peer !== null) {
                return (int) $peer->get('owner_uid');
            }
        }

        $default = $this->trails->find($trailId);

        return $default !== null ? (int) $default->get('owner_uid') : 0;
    }

    /**
     * @param list<BeaconShape|array<string, mixed>> $beacons
     * @return array{title: string, beacons: string, owner_uid: int, origin: string, langcode: string}
     */
    private function valuesFor(string $title, array $beacons, string $origin, int $ownerUid, string $langcode): array
    {
        return [
            'title' => $title,
            'beacons' => $this->encodeBeacons($beacons),
            'owner_uid' => $ownerUid,
            'origin' => $origin,
            'langcode' => $langcode,
        ];
    }

    /**
     * @param list<BeaconShape|array<string, mixed>> $beacons
     */
    private function encodeBeacons(array $beacons): string
    {
        $normalized = array_map(
            static fn(array $beacon): array => [
                'anchor_id' => (string) ($beacon['anchor_id'] ?? ''),
                'content' => (string) ($beacon['content'] ?? ''),
                'order' => (int) ($beacon['order'] ?? 0),
            ],
            $beacons,
        );

        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<BeaconShape>
     */
    private function decodeBeacons(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $beacon) {
            if (!\is_array($beacon)) {
                continue;
            }
            $out[] = [
                'anchor_id' => (string) ($beacon['anchor_id'] ?? ''),
                'content' => (string) ($beacon['content'] ?? ''),
                'order' => (int) ($beacon['order'] ?? 0),
            ];
        }

        return $out;
    }
}
