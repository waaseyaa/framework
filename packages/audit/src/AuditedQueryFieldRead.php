<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\QueryFieldReadCapability;
use Waaseyaa\Access\Query\QueryFieldReadRequest;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadKind;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;

/** Dormant compiler boundary for strictly audited non-public query operations. @api */
final readonly class AuditedQueryFieldRead
{
    public function __construct(private CapabilityRegistryInterface $capabilities, private StrictPrivilegedReadLedgerInterface $ledger) {}

    public function reserve(QueryFieldReadCapability $capability, CapabilityExecutionBoundary $boundary, QueryFieldReadRequest $request): AuditedQueryReservation
    {
        $authorization = $this->capabilities->authorizationFor($capability, $boundary);
        if ($authorization === null) {
            throw new FieldReadDenied('A live registered query-field capability is required.');
        }
        $declaration = $authorization->declaration;
        if (!$declaration->wildcard && (
            !in_array($request->entityTypeId, $declaration->entityTypes, true)
            || array_diff($request->bundles, $declaration->bundles) !== []
            || array_diff($request->fields, $declaration->queryFields) !== []
            || array_filter(
                $request->operations,
                static fn($operation): bool => !in_array($operation, $declaration->queryOperations, true),
            ) !== []
        )) {
            throw new FieldReadDenied(sprintf('Capability issuer "%s" does not grant this query shape.', $declaration->issuer));
        }
        $context = $authorization->context;
        $receipt = $this->ledger->reserve(new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Query,
            reason: $declaration->reason,
            issuer: $declaration->issuer,
            actorSemantics: $context->actorSemantics,
            actorId: $context->actorId,
            entityTypeId: $request->entityTypeId,
            entityId: null,
            fields: $request->fields,
            bundles: $request->bundles,
            tenantId: $context->tenantId,
            communityId: $context->communityId,
            queryFingerprint: $request->fingerprint,
            queryOperations: $request->operations,
            classificationGeneration: $context->classificationGeneration,
            policyGeneration: $context->policyGeneration,
            correlationId: $context->executionBoundary,
            callSite: $this->callSite(),
        ));
        return new AuditedQueryReservation($this->ledger, $receipt);
    }

    private function callSite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3) as $frame) {
            if (($frame['class'] ?? null) !== self::class && isset($frame['file'], $frame['line'])) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }
        return self::class . '::reserve';
    }
}
