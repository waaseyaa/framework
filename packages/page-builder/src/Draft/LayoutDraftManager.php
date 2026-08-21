<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Command\EditCommand;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Draft\Exception\InvalidStoredLayoutException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;

/** @api */
final readonly class LayoutDraftManager
{
    public function __construct(
        private LayoutDraftGatewayInterface $gateway,
        private CanonicalLayoutCodec $codec,
        private LayoutValidator $validator,
        private LayoutEditor $editor,
    ) {}

    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraft
    {
        return $this->hydrate($this->gateway->read($actor, $entityId));
    }

    /**
     * @param list<string> $saveAdvisoryAcknowledgements Exact candidate-bound
     *        receipts for advisories this edit has already reviewed. Routed
     *        through {@see LayoutSaveAdvisoryAcknowledgementDispatcher}, which
     *        refuses rather than dropping them on a gateway that cannot carry
     *        them.
     */
    public function apply(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedEntityRevisionId,
        string $expectedDocumentFingerprint,
        EditCommand $command,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraft {
        if ($expectedEntityRevisionId < 1 || '' === $idempotencyKey) {
            throw new \InvalidArgumentException('A positive expected entity revision and idempotency key are required.');
        }

        $current = $this->read($actor, $entityId);
        if ($current->entityRevisionId !== $expectedEntityRevisionId) {
            throw new StaleEntityRevisionException($expectedEntityRevisionId, $current->entityRevisionId);
        }

        $edited = $this->editor->apply($current->document, $expectedDocumentFingerprint, $command);
        $saved = LayoutSaveAdvisoryAcknowledgementDispatcher::update(
            $this->gateway,
            $actor,
            $entityId,
            $this->codec->encode($edited->document()),
            $expectedEntityRevisionId,
            $idempotencyKey,
            $saveAdvisoryAcknowledgements,
        );

        return $this->hydrate($saved);
    }

    private function hydrate(LayoutDraftSnapshot $snapshot): LayoutDraft
    {
        $document = $this->codec->decode($snapshot->encodedLayout);
        $validation = $this->validator->validate($document);
        if (!$validation->isValid()) {
            throw new InvalidStoredLayoutException($validation->violations());
        }

        return new LayoutDraft(
            $snapshot->entityId,
            $snapshot->entityRevisionId,
            $document,
            $this->editor->fingerprint($document),
        );
    }
}
