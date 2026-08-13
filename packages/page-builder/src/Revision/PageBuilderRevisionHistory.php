<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Revision;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Draft\Exception\InvalidStoredLayoutException;
use Waaseyaa\PageBuilder\Draft\LayoutDraft;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

/** Validates historical layouts before exposing or restoring them. @api */
final readonly class PageBuilderRevisionHistory
{
    public function __construct(
        private PageBuilderRevisionGatewayInterface $gateway,
        private CanonicalLayoutCodec $codec,
        private LayoutValidator $validator,
        private LayoutEditor $editor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(AuthorizationPrincipalInterface $actor, string $entityId): array
    {
        return array_map(function (PageBuilderRevisionSnapshot $revision): array {
            $draft = $this->hydrate($revision);

            return [
                'revision_id' => $revision->revisionId,
                'created_at' => $revision->createdAt?->format(DATE_ATOM),
                'author_id' => $revision->authorId,
                'log' => $revision->log,
                'is_current' => $revision->isCurrent,
                'is_latest' => $revision->isLatest,
                'document_fingerprint' => $draft->documentFingerprint,
                'block_count' => $this->blockCount($draft),
            ];
        }, $this->gateway->list($actor, $entityId));
    }

    public function read(AuthorizationPrincipalInterface $actor, string $entityId, int $revisionId): LayoutDraft
    {
        return $this->hydrate($this->gateway->readRevision($actor, $entityId, $revisionId));
    }

    public function restore(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $targetRevisionId,
        int $expectedCurrentRevisionId,
        string $idempotencyKey,
    ): LayoutDraft {
        return $this->hydrateDraft($this->gateway->restore(
            $actor,
            $entityId,
            $targetRevisionId,
            $expectedCurrentRevisionId,
            $idempotencyKey,
        ));
    }

    private function hydrate(PageBuilderRevisionSnapshot $snapshot): LayoutDraft
    {
        return $this->hydrateValues($snapshot->entityId, $snapshot->revisionId, $snapshot->encodedLayout);
    }

    private function hydrateDraft(\Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot $snapshot): LayoutDraft
    {
        return $this->hydrateValues($snapshot->entityId, $snapshot->entityRevisionId, $snapshot->encodedLayout);
    }

    private function hydrateValues(string $entityId, int $revisionId, string $encodedLayout): LayoutDraft
    {
        $document = $this->codec->decode($encodedLayout);
        $validation = $this->validator->validate($document);
        if (!$validation->isValid()) {
            throw new InvalidStoredLayoutException($validation->violations());
        }

        return new LayoutDraft($entityId, $revisionId, $document, $this->editor->fingerprint($document));
    }

    private function blockCount(LayoutDraft $draft): int
    {
        $count = 0;
        foreach ($draft->document->sections() as $section) {
            $regions = $section['regions'] ?? [];
            if (!is_array($regions)) {
                continue;
            }
            foreach ($regions as $blocks) {
                if (!is_array($blocks)) {
                    continue;
                }
                $count += count($blocks);
            }
        }

        return $count;
    }
}
