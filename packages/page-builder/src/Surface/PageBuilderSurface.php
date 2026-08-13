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
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;

/** @api */
final readonly class PageBuilderSurface
{
    public function __construct(
        private string $permission,
        private DefinitionRegistry $definitions,
        private LayoutDraftManager $drafts,
        private RevisionPreviewGatewayInterface $previews,
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

    public function apply(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedEntityRevisionId,
        string $expectedDocumentFingerprint,
        EditCommand $command,
        string $idempotencyKey,
    ): LayoutDraft {
        $this->assertAllowed($actor);

        return $this->drafts->apply(
            $actor,
            $entityId,
            $expectedEntityRevisionId,
            $expectedDocumentFingerprint,
            $command,
            $idempotencyKey,
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

    private function assertAllowed(AuthorizationPrincipalInterface $actor): void
    {
        if (!$actor->hasPermission($this->permission)) {
            throw new PageBuilderAccessDeniedException('The acting principal cannot use this page-builder surface.');
        }
    }
}
