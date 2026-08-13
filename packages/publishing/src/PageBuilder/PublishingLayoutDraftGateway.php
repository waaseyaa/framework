<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;

/** @api */
final readonly class PublishingLayoutDraftGateway implements LayoutDraftGatewayInterface
{
    public function __construct(
        private ContentDraftMutationInterface $publisher,
        private string $layoutField,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $layoutField)) {
            throw new \InvalidArgumentException("Invalid layout field: {$layoutField}");
        }
    }

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
    {
        try {
            return $this->snapshot($this->publisher->get($actor, $entityId));
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page draft is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page draft was not found.', previous: $exception);
        }
    }

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot {
        try {
            return $this->snapshot($this->publisher->updateDraft(
                $actor,
                $entityId,
                [$this->layoutField => $encodedLayout],
                $expectedRevisionId,
                $idempotencyKey,
            ));
        } catch (ContentAuthorizationException $exception) {
            throw new PageBuilderAccessDeniedException('The page draft is not accessible.', previous: $exception);
        } catch (ContentNotFoundException $exception) {
            throw new PageBuilderDraftNotFoundException('The page draft was not found.', previous: $exception);
        } catch (RevisionConflictException $exception) {
            throw new StaleEntityRevisionException(
                $exception->expectedRevisionId,
                $exception->currentRevisionId,
            );
        }
    }

    /** @param array<string, mixed> $value */
    private function snapshot(array $value): LayoutDraftSnapshot
    {
        $id = $value['id'] ?? null;
        $revisionId = $value['revision_id'] ?? null;
        $encodedLayout = $value[$this->layoutField] ?? null;
        if ((!is_string($id) && !is_int($id)) || !is_int($revisionId) || !is_string($encodedLayout)) {
            throw new \UnexpectedValueException('Publishing snapshot is missing the governed layout identity, revision, or value.');
        }

        return new LayoutDraftSnapshot((string) $id, $revisionId, $encodedLayout);
    }
}
