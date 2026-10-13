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

    /**
     * Check ordered metadata probes, stopping at the first unsupported field.
     * All planned attempts are reserved durably before the first invocation;
     * no result leaves this method before its outcomes are committed.
     *
     * @param non-empty-list<FieldDefinition> $fields
     * @return list<bool> Results through the first false, or all results.
     * @internal Used by definition validation, never for data operations.
     */
    public function supportsQueries(array $fields, EntityQuery $query): array
    {
        if (!$this->audit instanceof BatchStrictFieldStorageGatewayAuditInterface) {
            $results = [];
            foreach ($fields as $field) {
                $results[] = $supported = $this->supportsQuery($field, $query);
                if (!$supported) {
                    break;
                }
            }
            return $results;
        }
        $attempts = array_map(fn(FieldDefinition $field) => new FieldStorageGatewayAttempt(
            backendId: $this->id(),
            fingerprint: $this->registeredFingerprint,
            operation: FieldStorageGatewayOperation::SupportsQuery,
            entityTypeId: $field->getTargetEntityTypeId(),
            entityId: null,
            fieldName: $field->getName(),
        ), $fields);
        $receipts = $this->audit->reserveMany($attempts);
        $this->validateProbeReceipts($receipts, $attempts);
        $results = [];
        $outcomes = [];
        $error = null;
        $stopped = false;
        foreach ($receipts as $index => $receipt) {
            $started = false;
            $failure = null;
            if ($stopped) {
                $failure = new FieldStorageGatewayFailure($receipt->attempt, \LogicException::class, false);
            } else {
                try {
                    $result = (bool) $this->callBackend(FieldStorageGatewayOperation::SupportsQuery, null, $fields[$index], null, $query, $started);
                    $results[] = $result;
                    $stopped = !$result;
                } catch (\Throwable $caught) {
                    $error = $caught;
                    $stopped = true;
                    $failure = new FieldStorageGatewayFailure($receipt->attempt, $caught::class, $started);
                }
            }
            $outcomes[] = ['receipt' => $receipt, 'failure' => $failure];
        }
        $this->audit->finalizeMany($outcomes);
        if ($error !== null) {
            throw $error;
        }
        return $results;
    }

    /**
     * Validate the untrusted extension return at the authority boundary.
     *
     * @param array<array-key, mixed> $receipts
     * @param list<FieldStorageGatewayAttempt> $attempts
     */
    private function validateProbeReceipts(array $receipts, array $attempts): void
    {
        // A malformed receipt set cannot grant invocation authority.
        if (!array_is_list($receipts) || count($receipts) !== count($attempts)) {
            throw new \LogicException('Strict query audit returned an invalid receipt set.');
        }
        foreach ($receipts as $index => $receipt) {
            if (!$receipt instanceof FieldStorageGatewayAuditReceipt || $receipt->attempt !== $attempts[$index]) {
                throw new \LogicException('Strict query audit returned a receipt for a different attempt.');
            }
        }
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
            $result = $this->callBackend($operation, $entity, $field, $value, $query, $backendInvocationStarted);
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

    private function callBackend(
        FieldStorageGatewayOperation $operation,
        ?EntityInterface $entity,
        ?FieldDefinition $field,
        mixed $value,
        ?EntityQuery $query,
        bool &$started,
    ): mixed {
        $this->authority->assertFreshFingerprint();
        $input = $this->authority->input($operation, $entity, $field, $value, $query);
        $started = true;
        $output = $this->backend->invoke($this->authority->role(), $input);
        return $this->authority->output($input, $output);
    }
}
