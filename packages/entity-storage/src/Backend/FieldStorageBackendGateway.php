<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;

/** Registrar-owned facade; the raw V2 implementation is never exposed. @api */
final class FieldStorageBackendGateway
{
    private readonly FieldStorageGatewayAuthority $authority;

    /** @internal Constructed only while BackendRegistrar binds a validated V2 registration. */
    public function __construct(
        private readonly string $registeredId,
        private readonly FieldStorageBackendV2Interface $backend,
        private readonly string $registeredFingerprint,
        private readonly StrictFieldStorageGatewayAuditInterface $audit,
    ) {
        $this->authority = new FieldStorageGatewayAuthority($backend, $registeredId, $registeredFingerprint);
    }

    public function id(): string
    {
        return $this->registeredId;
    }
    public function fingerprint(): string
    {
        return $this->registeredFingerprint;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return $this->invoke(FieldStorageGatewayOperation::Read, $entity, $field, null, null);
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void
    {
        $this->invoke(FieldStorageGatewayOperation::Write, $entity, $field, $value, null);
    }

    public function delete(EntityInterface $entity): void
    {
        $this->invoke(FieldStorageGatewayOperation::Delete, $entity, null, null, null);
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return (bool) $this->invoke(FieldStorageGatewayOperation::SupportsQuery, null, $field, null, $query);
    }

    private function invoke(
        FieldStorageGatewayOperation $operation,
        ?EntityInterface $entity,
        ?FieldDefinition $field,
        mixed $value,
        ?EntityQuery $query,
    ): mixed {
        $attempt = new FieldStorageGatewayAttempt(
            backendId: $this->id(),
            fingerprint: $this->registeredFingerprint,
            operation: $operation,
            entityTypeId: $entity?->getEntityTypeId() ?? $field?->getTargetEntityTypeId() ?? '',
            entityId: $entity?->id(),
            fieldName: $field?->getName(),
        );

        // Strict reservation is deliberately before every validation or backend
        // call. If it cannot be recorded, a write has not begun.
        $receipt = $this->audit->reserve($attempt);
        $backendInvocationStarted = false;
        try {
            if ($receipt->attempt !== $attempt) {
                throw new \LogicException('Strict field-storage audit returned a receipt for a different attempt.');
            }
            $this->authority->assertFreshFingerprint();
            $input = $this->authority->input($operation, $entity, $field, $value, $query);
            $backendInvocationStarted = true;
            $output = $this->backend->invoke($this->authority->role(), $input);
            $result = $this->authority->output($input, $output);
            $this->audit->succeed($receipt);

            return $result;
        } catch (\Throwable $e) {
            $this->audit->fail($receipt, new FieldStorageGatewayFailure(
                attempt: $attempt,
                causeClass: $e::class,
                backendInvocationStarted: $backendInvocationStarted,
            ));
            throw $e;
        }
    }
}
