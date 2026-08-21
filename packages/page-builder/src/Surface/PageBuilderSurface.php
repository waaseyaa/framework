<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Surface;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Command\EditCommand;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Draft\LayoutDraft;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Revision\Exception\PageBuilderHistoryUnavailableException;
use Waaseyaa\PageBuilder\Revision\PageBuilderRevisionHistory;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;

/** @api */
final readonly class PageBuilderSurface
{
    public function __construct(
        private string $permission,
        private DefinitionRegistry $definitions,
        private LayoutDraftManager $drafts,
        private RevisionPreviewGatewayInterface $previews,
        private ?PageBuilderRevisionHistory $history = null,
    ) {
        if ('' === $permission) {
            throw new \InvalidArgumentException('A page-builder permission is required.');
        }
    }

    /** @return array<string, mixed> */
    public function definitions(AuthorizationPrincipalInterface $actor): array
    {
        $this->assertAllowed($actor);

        return $this->definitions->manifest();
    }

    public function draft(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraft
    {
        $this->assertAllowed($actor);

        return $this->drafts->read($actor, $entityId);
    }

    /** @param list<string> $saveAdvisoryAcknowledgements Exact candidate-bound receipts. */
    public function apply(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedEntityRevisionId,
        string $expectedDocumentFingerprint,
        EditCommand $command,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraft {
        $this->assertAllowed($actor);

        return $this->drafts->apply(
            $actor,
            $entityId,
            $expectedEntityRevisionId,
            $expectedDocumentFingerprint,
            $command,
            $idempotencyKey,
            $saveAdvisoryAcknowledgements,
        );
    }

    public function preview(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant {
        $this->assertAllowed($actor);

        return $this->previews->issue($actor, $entityId, $expectedRevisionId);
    }

    /** @return list<array<string, mixed>> */
    public function history(AuthorizationPrincipalInterface $actor, string $entityId): array
    {
        $this->assertAllowed($actor);

        return $this->history?->list($actor, $entityId) ?? [];
    }

    public function revision(AuthorizationPrincipalInterface $actor, string $entityId, int $revisionId): LayoutDraft
    {
        $this->assertAllowed($actor);

        return $this->requireHistory()->read($actor, $entityId, $revisionId);
    }

    public function restore(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $targetRevisionId,
        int $expectedCurrentRevisionId,
        string $idempotencyKey,
    ): LayoutDraft {
        $this->assertAllowed($actor);

        return $this->requireHistory()->restore(
            $actor,
            $entityId,
            $targetRevisionId,
            $expectedCurrentRevisionId,
            $idempotencyKey,
        );
    }

    private function requireHistory(): PageBuilderRevisionHistory
    {
        if ($this->history === null) {
            throw new PageBuilderHistoryUnavailableException('Revision history is not configured for this page-builder surface.');
        }

        return $this->history;
    }

    private function assertAllowed(AuthorizationPrincipalInterface $actor): void
    {
        if (!$actor->hasPermission($this->permission)) {
            throw new PageBuilderAccessDeniedException('The acting principal cannot use this page-builder surface.');
        }
    }
}
