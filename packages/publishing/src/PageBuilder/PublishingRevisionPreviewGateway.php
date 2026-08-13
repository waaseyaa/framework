<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewUrlGeneratorInterface;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\Publishing\ContentRevisionPreviewInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Preview\PreviewLinkService;

/** @api */
final readonly class PublishingRevisionPreviewGateway implements RevisionPreviewGatewayInterface
{
    public function __construct(
        private ContentRevisionPreviewInterface $publisher,
        private PreviewLinkService $links,
        private RevisionPreviewUrlGeneratorInterface $urls,
        private int $ttlSeconds = 1800,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Preview TTL must be positive.');
        }
    }

    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant {
        try {
            $grant = $this->publisher->previewRevision(
                $actor,
                $entityId,
                $expectedRevisionId,
                $this->links,
                $this->ttlSeconds,
            );
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page preview is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page preview was not found.', previous: $exception);
        } catch (RevisionConflictException $exception) {
            throw new StaleEntityRevisionException(
                $exception->expectedRevisionId,
                $exception->currentRevisionId,
            );
        }

        return new RevisionPreviewGrant(
            (string) $grant['id'],
            $grant['revision_id'],
            $grant['expires_at'],
            $grant['signature'],
            $this->urls->generate(
                (string) $grant['id'],
                $grant['revision_id'],
                $grant['expires_at'],
                $grant['signature'],
            ),
        );
    }
}
