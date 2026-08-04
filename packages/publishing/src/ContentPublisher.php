<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Exception\ContentValidationException;
use Waaseyaa\Publishing\Exception\SlugConflictException;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;

/**
 * The single mutation door for one publishable content shape.
 *
 * Composes ONLY existing framework primitives — repository revisions,
 * `SaveContext::withExpectedRevisionId()` optimistic concurrency, the entity
 * access handler, and the append-only audit writer. It adds the editorial
 * contract every CMS consumer otherwise re-derives: capability gating,
 * bundle-scoped writable schema, field-specific validation, input-side HTML
 * sanitization against an explicit allowlist, slug uniqueness, idempotent
 * mutations, and publish/unpublish/rollback semantics that never delete
 * history.
 *
 * Transports (MCP content tools, HTTP controllers) must stay thin adapters
 * over this service; nothing may write content around it.
 *
 * @api
 */
final class ContentPublisher
{
    private readonly ContentMutationSnapshotReader $snapshotReader;

    public function __construct(
        private readonly ContentTypeDescriptor $descriptor,
        private readonly EntityRepository $repository,
        private readonly IdempotencyStore $idempotency,
        private readonly ?AuditWriterInterface $audit = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?ContentPublicationTransitionerInterface $publicationTransitioner = null,
    ) {
        $this->snapshotReader = new ContentMutationSnapshotReader($descriptor);
    }

    // ------------------------------------------------------------------
    // Reads (capability-gated: this surface is for publishers)
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function list(AuthorizationPrincipalInterface $actor, bool $publishedOnly = false, int $limit = 50, int $offset = 0): array
    {
        $this->requireCapability($actor);

        $criteria = $this->bundleCriteria();
        if ($publishedOnly) {
            $criteria[$this->descriptor->statusField] = 1;
        }

        $entities = $this->repository->findBy($criteria, null, $limit + $offset);
        $entities = \array_slice($this->filterBundle($entities), $offset, $limit);

        return array_map($this->snapshot(...), $entities);
    }

    /** @return array<string, mixed> */
    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array
    {
        $this->requireCapability($actor);

        return $this->snapshot($this->load($idOrSlug));
    }

    /** @return list<array<string, mixed>> */
    public function revisions(AuthorizationPrincipalInterface $actor, string $id): array
    {
        $this->requireCapability($actor);
        $this->load($id); // NOT_FOUND if absent

        $out = [];
        foreach ($this->repository->listRevisions($id) as $revision) {
            $meta = $revision instanceof RevisionableEntityInterface ? $revision->revisionMetadata() : null;
            $out[] = [
                'revision_id' => (int) ($revision instanceof RevisionableEntityInterface ? $revision->revisionId() : 0),
                'created_at' => $meta?->revisionCreatedAt->format(\DateTimeInterface::ATOM),
                'author_uid' => $meta?->revisionAuthor,
                'log' => $meta?->revisionLog,
                'status' => (bool) (int) $this->snapshotReader->field($revision, $this->descriptor->statusField),
            ];
        }

        return $out;
    }

    /**
     * Issue a short-lived signed preview grant for the (possibly unpublished)
     * content. Mutates nothing; the grant issuance is audited. The transport
     * layer turns the grant into a URL for the app's preview route.
     *
     * @return array{id: int|string|null, entity_type: string, expires_at: int, signature: string}
     */
    public function preview(AuthorizationPrincipalInterface $actor, string $idOrSlug, Preview\PreviewLinkService $links, int $ttlSeconds = 1800): array
    {
        $this->requireCapability($actor);
        $entity = $this->load($idOrSlug);

        $token = $links->issue($this->descriptor->entityTypeId, (string) $entity->id(), $ttlSeconds);
        $this->auditRecord(AuditEventKind::ContentPreviewIssued, $actor, $entity, ['expires_at' => $token->expiresAt]);

        return [
            'id' => $entity->id(),
            'entity_type' => $this->descriptor->entityTypeId,
            'expires_at' => $token->expiresAt,
            'signature' => $token->signature,
        ];
    }

    // ------------------------------------------------------------------
    // Mutations (capability + entity gate + validation + idempotency)
    // ------------------------------------------------------------------

    /**
     * Create an UNPUBLISHED draft. The status field is forced off regardless
     * of payload (payloads may not carry it at all).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function createDraft(AuthorizationPrincipalInterface $actor, array $values, string $idempotencyKey): array
    {
        $this->requireCapability($actor);
        $this->requireEntityCreateAccess($actor);

        return $this->idempotency->execute($idempotencyKey, 'createDraft', $values, function () use ($actor, $values): array {
            $clean = $this->validatePayload($values, existing: null);
            $this->assertSlugFree((string) $clean[$this->descriptor->slugField], excludeId: null);

            $entity = $this->repository->create($clean + $this->bundleCriteria());
            $entity = $entity->set($this->descriptor->statusField, 0);
            $this->stampLog($entity, 'Draft created via publishing surface.');
            $this->repository->save($entity, true, $this->saveContext($actor));

            $saved = $this->reload($entity);
            $this->auditRecord(AuditEventKind::ContentDraftSaved, $actor, $saved);

            return $this->snapshot($saved);
        }, $this->idempotencyNamespace());
    }

    /**
     * @param array<string, mixed> $values Partial payload; omitted fields keep their value.
     * @return array<string, mixed>
     */
    public function updateDraft(AuthorizationPrincipalInterface $actor, string $id, array $values, int $expectedRevisionId, string $idempotencyKey): array
    {
        $this->requireCapability($actor);

        $request = ['id' => $id, 'values' => $values, 'expected_revision_id' => $expectedRevisionId];

        return $this->idempotency->execute($idempotencyKey, 'updateDraft', $request, function () use ($actor, $id, $values, $expectedRevisionId): array {
            $entity = $this->load($id);
            $this->requireEntityUpdateAccess($actor, $entity);

            $clean = $this->validatePayload($values, existing: $entity);
            $slug = (string) ($clean[$this->descriptor->slugField]
                ?? $this->snapshotReader->field($entity, $this->descriptor->slugField));
            $this->assertSlugFree($slug, excludeId: (string) $entity->id());

            foreach ($clean as $field => $value) {
                $entity = $entity->set($field, $value);
            }
            $this->stampLog($entity, 'Draft updated via publishing surface.');
            $this->repository->save($entity, true, $this->saveContext($actor, $expectedRevisionId));

            $saved = $this->reload($entity);
            $this->auditRecord(AuditEventKind::ContentDraftSaved, $actor, $saved);

            return $this->snapshot($saved);
        }, $this->idempotencyNamespace());
    }

    /**
     * Atomic publish: one revision-cutting save flips the status flag with the
     * operator's note. Listings, search, and render caches react through the
     * framework's POST_SAVE listeners — outside this write, never blocking it.
     *
     * @return array<string, mixed>
     */
    public function publish(AuthorizationPrincipalInterface $actor, string $id, int $expectedRevisionId, string $idempotencyKey, string $note = ''): array
    {
        return $this->setPublicationStatus($actor, $id, true, $expectedRevisionId, $idempotencyKey, $note, AuditEventKind::ContentPublished, 'publish');
    }

    /**
     * Unpublish: the record and its full revision history are preserved.
     *
     * @return array<string, mixed>
     */
    public function unpublish(AuthorizationPrincipalInterface $actor, string $id, int $expectedRevisionId, string $idempotencyKey, string $note = ''): array
    {
        return $this->setPublicationStatus($actor, $id, false, $expectedRevisionId, $idempotencyKey, $note, AuditEventKind::ContentUnpublished, 'unpublish');
    }

    /**
     * Restore a prior revision AS A NEW revision — history is never deleted.
     *
     * @return array<string, mixed>
     */
    public function rollback(AuthorizationPrincipalInterface $actor, string $id, int $targetRevisionId, string $idempotencyKey, string $note = ''): array
    {
        $this->requireCapability($actor);

        $request = [
            'id' => $id,
            'target_revision_id' => $targetRevisionId,
            'note' => $note,
        ];

        return $this->idempotency->execute($idempotencyKey, 'rollback', $request, function () use ($actor, $id, $targetRevisionId, $note): array {
            $entity = $this->load($id);
            $this->requireEntityUpdateAccess($actor, $entity);

            // rollback() itself cuts exactly ONE new revision (with the
            // framework's revert events/audit); the operator note travels on
            // our audit record rather than a second revision.
            $restored = $this->repository->rollback($id, $targetRevisionId);

            $saved = $this->reload($restored);
            $this->auditRecord(AuditEventKind::ContentRolledBack, $actor, $saved, [
                'target_revision_id' => $targetRevisionId,
                'note' => $note,
            ]);

            return $this->snapshot($saved);
        }, $this->idempotencyNamespace());
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function setPublicationStatus(
        AuthorizationPrincipalInterface $actor,
        string $id,
        bool $published,
        int $expectedRevisionId,
        string $idempotencyKey,
        string $note,
        AuditEventKind $kind,
        string $operation,
    ): array {
        $this->requireCapability($actor);

        $request = [
            'id' => $id,
            'expected_revision_id' => $expectedRevisionId,
            'published' => $published,
            'note' => $note,
        ];

        return $this->idempotency->execute($idempotencyKey, $operation, $request, function () use ($actor, $id, $published, $expectedRevisionId, $note, $kind, $operation): array {
            $entity = $this->load($id);
            $this->requireEntityUpdateAccess($actor, $entity);

            if ($published) {
                // Publish re-validates the FULL effective document so an
                // incomplete draft cannot go public.
                $this->validatePayload([], existing: $entity, forPublish: true);
            }

            if ($this->publicationTransitioner?->supports($entity) === true) {
                $this->assertExpectedRevision($entity, $expectedRevisionId);
                $this->stampLog($entity, $note !== '' ? $note : ucfirst($operation) . 'ed via publishing surface.');
                $saved = $this->publicationTransitioner->setPublished($entity, $published, $actor);
                $this->auditRecord($kind, $actor, $saved);

                return $this->snapshot($saved);
            }

            $entity = $entity->set($this->descriptor->statusField, $published ? 1 : 0);
            $this->stampLog($entity, $note !== '' ? $note : ucfirst($operation) . 'ed via publishing surface.');
            $this->repository->save($entity, true, $this->saveContext($actor, $expectedRevisionId));

            $saved = $this->reload($entity);
            $this->auditRecord($kind, $actor, $saved);

            return $this->snapshot($saved);
        }, $this->idempotencyNamespace());
    }

    private function requireCapability(AuthorizationPrincipalInterface $actor): void
    {
        if (!$actor->hasPermission($this->descriptor->publishCapability)) {
            throw new ContentAuthorizationException(sprintf(
                'The "%s" capability is required for this content operation.',
                $this->descriptor->publishCapability,
            ));
        }
    }

    private function idempotencyNamespace(): string
    {
        return $this->descriptor->entityTypeId . ':' . ($this->descriptor->bundle ?? '_');
    }

    private function requireEntityCreateAccess(AuthorizationPrincipalInterface $actor): void
    {
        if ($this->accessHandler === null) {
            return;
        }
        $result = $this->accessHandler->checkCreateAccess($this->descriptor->entityTypeId, $this->descriptor->bundle ?? '', $actor);
        if (!$result->isAllowed()) {
            throw new ContentAuthorizationException('Entity create access denied.');
        }
    }

    private function requireEntityUpdateAccess(AuthorizationPrincipalInterface $actor, EntityInterface $entity): void
    {
        if ($this->accessHandler === null) {
            return;
        }
        if (!$this->accessHandler->check($entity, 'update', $actor)->isAllowed()) {
            throw new ContentAuthorizationException('Entity update access denied.');
        }
    }

    /**
     * Validate + sanitize a payload. With `$existing`, the payload is a patch:
     * required fields may be omitted (they keep their stored value) but may
     * not be blanked. With `$forPublish`, the merged document is re-checked
     * so publishing an incomplete draft fails field-specifically.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed> Sanitized payload (patch keys only).
     */
    private function validatePayload(array $values, ?EntityInterface $existing, bool $forPublish = false): array
    {
        $errors = new ValidationErrors();
        $clean = [];

        foreach ($values as $field => $value) {
            if ($field === $this->descriptor->statusField) {
                $errors->add($field, 'The publication status is not writable; use publish/unpublish.');
                continue;
            }
            $spec = $this->descriptor->writableFields[$field] ?? null;
            if ($spec === null) {
                $errors->add($field, 'This field is not part of the writable schema.');
                continue;
            }
            $errorCount = \count($errors->toArray());
            $typed = $this->coerce($field, $value, $spec, $errors);
            if (\count($errors->toArray()) !== $errorCount) {
                continue;
            }
            if ($spec->html && \is_string($typed)) {
                $typed = $this->descriptor->htmlSanitizer?->sanitize($typed) ?? $typed;
            }
            if ($spec->maxLength !== null && \is_string($typed) && mb_strlen($typed) > $spec->maxLength) {
                $errors->add($field, sprintf('Must be at most %d characters.', $spec->maxLength));
                continue;
            }
            $clean[$field] = $typed;
        }

        // Required-field discipline.
        foreach ($this->descriptor->writableFields as $field => $spec) {
            if (!$spec->required) {
                continue;
            }
            $provided = \array_key_exists($field, $clean);
            $effective = $provided
                ? $clean[$field]
                : ($existing !== null ? $this->snapshotReader->field($existing, $field) : null);
            if (($existing === null || $provided || $forPublish) && ($effective === null || $effective === '')) {
                $errors->add($field, 'This field is required.');
            }
        }

        // App editorial rules run against the MERGED effective document.
        $effectiveDoc = $clean;
        if ($existing !== null) {
            foreach ($this->descriptor->writableFields as $field => $spec) {
                if (!\array_key_exists($field, $effectiveDoc)) {
                    $effectiveDoc[$field] = $this->snapshotReader->field($existing, $field);
                }
            }
        }
        foreach ($this->descriptor->validators as $validator) {
            $validator->validate($effectiveDoc, $errors);
        }

        if (!$errors->isEmpty()) {
            throw new ContentValidationException($errors->toArray());
        }

        return $clean;
    }

    private function coerce(string $field, mixed $value, FieldSpec $spec, ValidationErrors $errors): mixed
    {
        if ($value === null) {
            if ($spec->nullable) {
                return null;
            }
            $errors->add($field, 'Must not be null.');

            return null;
        }

        switch ($spec->type) {
            case 'string':
            case 'text':
                if (!\is_string($value)) {
                    $errors->add($field, 'Must be a string.');

                    return null;
                }

                return $value;
            case 'bool':
                if (!\is_bool($value)) {
                    $errors->add($field, 'Must be a boolean.');
                }

                return \is_bool($value) ? $value : null;
            case 'int':
                if (!\is_int($value)) {
                    $errors->add($field, 'Must be an integer.');
                }

                return \is_int($value) ? $value : null;
            case 'date':
                if (!\is_string($value)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
                    || ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) === false
                    || $date->format('Y-m-d') !== $value
                ) {
                    $errors->add($field, 'Must be a real calendar date in YYYY-MM-DD format.');

                    return null;
                }

                return $value;
            case 'reference_list':
                if (!\is_array($value) || !array_is_list($value)) {
                    $errors->add($field, 'Must be a list of entity identifiers.');

                    return null;
                }
                if ($spec->maxItems !== null && \count($value) > $spec->maxItems) {
                    $errors->add($field, sprintf('Must contain at most %d references.', $spec->maxItems));

                    return null;
                }
                $normalized = [];
                foreach ($value as $item) {
                    if (\is_int($item) && $item > 0) {
                        $normalized[] = $item;
                        continue;
                    }
                    if (\is_string($item) && $item !== '' && mb_strlen($item) <= 190) {
                        $normalized[] = ctype_digit($item) && $item !== '0' ? (int) $item : $item;
                        continue;
                    }
                    $errors->add($field, 'Every reference must be a positive integer or a non-empty identifier of at most 190 characters.');

                    return null;
                }
                if (\count(array_unique(array_map(static fn(int|string $item): string => (string) $item, $normalized))) !== \count($normalized)) {
                    $errors->add($field, 'Reference lists must not contain duplicates.');

                    return null;
                }

                return $normalized;
        }
    }

    private function assertSlugFree(string $slug, ?string $excludeId): void
    {
        $matches = $this->filterBundle($this->repository->findBy([$this->descriptor->slugField => $slug], null, 2));
        foreach ($matches as $match) {
            if ($excludeId === null || (string) $match->id() !== $excludeId) {
                throw new SlugConflictException($this->descriptor->slugField, $slug);
            }
        }
    }

    private function load(string $idOrSlug): EntityInterface
    {
        if (ctype_digit($idOrSlug)) {
            $entity = $this->repository->find($idOrSlug);
            if ($entity !== null && $this->matchesBundle($entity)) {
                return $this->repository->loadWorkingCopy($idOrSlug) ?? $entity;
            }
        }
        $matches = $this->filterBundle($this->repository->findBy([$this->descriptor->slugField => $idOrSlug], null, 1));
        if ($matches !== []) {
            return $this->repository->loadWorkingCopy((string) $matches[0]->id()) ?? $matches[0];
        }

        throw new ContentNotFoundException($idOrSlug);
    }

    private function reload(EntityInterface $entity): EntityInterface
    {
        $reloaded = $this->repository->loadWorkingCopy((string) $entity->id())
            ?? $this->repository->find((string) $entity->id());

        return $reloaded ?? $entity;
    }

    private function saveContext(AuthorizationPrincipalInterface $actor, ?int $expectedRevisionId = null): SaveContext
    {
        $context = SaveContext::default();
        $uid = $actor->id();
        if (\is_int($uid) || ctype_digit($uid)) {
            $context = $context->withActorUid((int) $uid);
        }
        if ($expectedRevisionId !== null) {
            $context = $context->withExpectedRevisionId($expectedRevisionId);
        }

        return $context;
    }

    private function assertExpectedRevision(EntityInterface $entity, int $expectedRevisionId): void
    {
        $current = match (true) {
            $entity instanceof RevisionableInterface => $entity->getRevisionId(),
            $entity instanceof RevisionableEntityInterface => $entity->revisionId(),
            default => null,
        };
        $current = is_int($current) || (is_string($current) && ctype_digit($current)) ? (int) $current : null;
        if ($current !== $expectedRevisionId) {
            throw new RevisionConflictException(
                $entity->getEntityTypeId(),
                (string) $entity->id(),
                $expectedRevisionId,
                $current,
            );
        }
    }

    private function stampLog(EntityInterface $entity, string $note): void
    {
        if (method_exists($entity, 'setRevisionLog')) {
            $entity->setRevisionLog($note);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(EntityInterface $entity): array
    {
        return $this->snapshotReader->snapshot($entity);
    }

    /** @return array<string, string> */
    private function bundleCriteria(): array
    {
        // The bundle column criteria only applies to bundled types; the field
        // name is the entity type's bundle key, which for framework content
        // types is exposed as the entity's bundle() — criteria key supplied by
        // the app descriptor via a convention: bundled descriptors target
        // entity types whose bundle column is named 'type' (the framework
        // node shape). Bundle-less descriptors return no criteria.
        return $this->descriptor->bundle === null ? [] : ['type' => $this->descriptor->bundle];
    }

    /**
     * @param list<EntityInterface> $entities
     * @return list<EntityInterface>
     */
    private function filterBundle(array $entities): array
    {
        return array_values(array_filter($entities, $this->matchesBundle(...)));
    }

    private function matchesBundle(EntityInterface $entity): bool
    {
        return $this->descriptor->bundle === null || $entity->bundle() === $this->descriptor->bundle;
    }

    /** @param array<string, mixed> $extra */
    private function auditRecord(AuditEventKind $kind, AuthorizationPrincipalInterface $actor, EntityInterface $entity, array $extra = []): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $uid = $actor->id();
            $this->audit->record(new AuditEventDescriptor(
                kind: $kind,
                accountUid: \is_int($uid) || ctype_digit($uid) ? (int) $uid : null,
                subjectUri: sprintf('/content/%s/%s', $this->descriptor->entityTypeId, (string) $entity->id()),
                outcome: 'allowed',
                severity: 'info',
                entityTypeId: $this->descriptor->entityTypeId,
                attributes: $extra + [
                    'slug' => (string) $this->snapshotReader->field($entity, $this->descriptor->slugField),
                    'revision_id' => $entity instanceof RevisionableEntityInterface ? $entity->revisionId() : null,
                ],
            ));
        } catch (\Throwable) {
            // Audit is best-effort by contract; the mutation itself succeeded.
        }
    }
}
