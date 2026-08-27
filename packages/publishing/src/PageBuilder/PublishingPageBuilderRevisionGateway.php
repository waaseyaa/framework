<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Draft\InitialLayoutDocumentProviderInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionGatewayInterface;
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionSnapshot;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\Publishing\ContentRevisionHistoryInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;

/**
 * Publishing-backed page-builder history gateway.
 *
 * Revisions cut before a migrated entity was ever authored in the page
 * builder carry no stored layout document. Composing an
 * {@see InitialLayoutDocumentProviderInterface} projects the application's
 * initial document for exactly those revisions, so history over migrated
 * content works with the same seam the draft gateway uses. Without a provider
 * the historical refusal is unchanged.
 *
 * @api
 */
final readonly class PublishingPageBuilderRevisionGateway implements PageBuilderRevisionGatewayInterface
{
    public function __construct(
        private ContentRevisionHistoryInterface $publisher,
        private string $layoutField,
        private ?InitialLayoutDocumentProviderInterface $initialLayoutDocuments = null,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $layoutField)) {
            throw new \InvalidArgumentException("Invalid layout field: {$layoutField}");
        }
    }

    public function list(AuthorizationPrincipalInterface $actor, string $entityId): array
    {
        try {
            return array_map(
                fn(array $revision): PageBuilderRevisionSnapshot => $this->revisionSnapshot($entityId, $revision),
                $this->publisher->revisions($actor, $entityId),
            );
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page history is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page history was not found.', previous: $exception);
        }
    }

    public function readRevision(AuthorizationPrincipalInterface $actor, string $entityId, int $revisionId): PageBuilderRevisionSnapshot
    {
        try {
            return $this->revisionSnapshot($entityId, $this->publisher->revision($actor, $entityId, $revisionId));
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page revision is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page revision was not found.', previous: $exception);
        }
    }

    public function restore(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $targetRevisionId,
        int $expectedCurrentRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        try {
            $value = $this->publisher->rollback(
                $actor,
                $entityId,
                $targetRevisionId,
                $idempotencyKey,
                expectedCurrentRevisionId: $expectedCurrentRevisionId,
            );
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page revision is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page revision was not found.', previous: $exception);
        } catch (RevisionConflictException $exception) {
            throw new StaleEntityRevisionException($exception->expectedRevisionId, $exception->currentRevisionId);
        }

        $revisionId = $value['revision_id'] ?? null;
        $layout = $this->layoutDocument($entityId, $value[$this->layoutField] ?? null);
        if (!is_int($revisionId) || !is_string($layout)) {
            throw new \UnexpectedValueException('Restored publishing snapshot is missing the revision or governed layout.');
        }

        return new LayoutDraftSnapshot($entityId, $revisionId, $layout);
    }

    /** @param array<string, mixed> $value */
    private function revisionSnapshot(string $entityId, array $value): PageBuilderRevisionSnapshot
    {
        $revisionId = $value['revision_id'] ?? null;
        $layout = $this->layoutDocument($entityId, $value[$this->layoutField] ?? null);
        if (!is_int($revisionId) || !is_string($layout)) {
            throw new \UnexpectedValueException('Publishing revision is missing its revision or governed layout.');
        }

        $createdAt = isset($value['created_at']) && is_string($value['created_at'])
            ? new \DateTimeImmutable($value['created_at'])
            : null;

        return new PageBuilderRevisionSnapshot(
            $entityId,
            $revisionId,
            $layout,
            $createdAt,
            isset($value['author_uid']) ? (int) $value['author_uid'] : null,
            isset($value['log']) && is_string($value['log']) ? $value['log'] : null,
            (bool) ($value['is_current'] ?? false),
            (bool) ($value['is_latest'] ?? false),
        );
    }

    /** Absent means never authored: NULL or an empty/whitespace-only string, never another corrupt type. */
    private function layoutDocument(string $entityId, mixed $stored): mixed
    {
        if (null === $this->initialLayoutDocuments || (null !== $stored && (!is_string($stored) || '' !== trim($stored)))) {
            return $stored;
        }

        $document = $this->initialLayoutDocuments->initialEncodedLayout($entityId);
        if ('' === trim($document)) {
            throw new \UnexpectedValueException('The initial layout document provider returned an empty document.');
        }

        return $document;
    }
}
