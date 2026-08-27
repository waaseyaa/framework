<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\PageBuilder\Draft\AdvisoryAwareLayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\PageBuilder\Draft\InitialLayoutDocumentProviderInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\Publishing\ContentDraftMutationInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException;
use Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException;
use Waaseyaa\Publishing\SaveAdvisoryAcknowledgementDispatcher;

/**
 * Publishing-backed layout draft gateway.
 *
 * Carries save advisory acknowledgements end to end: receipts arrive through
 * the layout seam and are handed to the draft-mutation seam by
 * {@see SaveAdvisoryAcknowledgementDispatcher}, which refuses rather than
 * dropping them when the wrapped publisher cannot carry them. An unacknowledged
 * advisory comes back as a page-builder-typed {@see LayoutSaveAdvisoryException}
 * so a page-builder transport can present the review without depending on this
 * package, the same translation this gateway already performs for authorization
 * and not-found outcomes.
 *
 * An entity migrated from another CMS has no stored layout document, a state
 * {@see LayoutDraftSnapshot} cannot legally hold. Composing an
 * {@see InitialLayoutDocumentProviderInterface} lets the application supply
 * the document for that case as a read projection — nothing is written until
 * an editor saves. Without a provider the historical refusal is unchanged.
 *
 * @api
 */
final readonly class PublishingLayoutDraftGateway implements AdvisoryAwareLayoutDraftGatewayInterface
{
    public function __construct(
        private ContentDraftMutationInterface $publisher,
        private string $layoutField,
        private ?InitialLayoutDocumentProviderInterface $initialLayoutDocuments = null,
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

    /** @param list<string> $saveAdvisoryAcknowledgements Exact candidate-bound receipts. */
    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraftSnapshot {
        try {
            return $this->snapshot(SaveAdvisoryAcknowledgementDispatcher::updateDraft(
                $this->publisher,
                $actor,
                $entityId,
                [$this->layoutField => $encodedLayout],
                $expectedRevisionId,
                $idempotencyKey,
                $saveAdvisoryAcknowledgements,
            ));
        } catch (ContentSaveAdvisoryException $exception) {
            throw new LayoutSaveAdvisoryException($exception->meta['save_advisories'] ?? [], previous: $exception);
        } catch (UnsupportedSaveAdvisoryAcknowledgementException $exception) {
            throw new UnsupportedLayoutSaveAdvisoryAcknowledgementException(previous: $exception);
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
        if ((is_string($id) || is_int($id)) && null !== $this->initialLayoutDocuments && self::isAbsentDocument($encodedLayout)) {
            $encodedLayout = self::requireDocument($this->initialLayoutDocuments->initialEncodedLayout((string) $id));
        }
        if ((!is_string($id) && !is_int($id)) || !is_int($revisionId) || !is_string($encodedLayout)) {
            throw new \UnexpectedValueException('Publishing snapshot is missing the governed layout identity, revision, or value.');
        }

        return new LayoutDraftSnapshot((string) $id, $revisionId, $encodedLayout);
    }

    /** Absent means never authored: NULL or an empty/whitespace-only string, never another corrupt type. */
    private static function isAbsentDocument(mixed $stored): bool
    {
        return null === $stored || (is_string($stored) && '' === trim($stored));
    }

    private static function requireDocument(string $document): string
    {
        if ('' === trim($document)) {
            throw new \UnexpectedValueException('The initial layout document provider returned an empty document.');
        }

        return $document;
    }
}
