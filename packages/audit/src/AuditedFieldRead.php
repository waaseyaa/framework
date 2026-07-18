<?php

declare(strict_types=1);

namespace Waaseyaa\Audit;

use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadKind;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;

/**
 * Explicit value-read escape hatch backed by registry authority and the strict
 * reservation-before-obtain ledger.
 *
 * @api
 */
final readonly class AuditedFieldRead
{
    /** @var \Closure(EntityBase, string): mixed */
    private \Closure $firstPartyObtain;

    public function __construct(
        private CapabilityRegistryInterface $capabilities,
        private StrictPrivilegedReadLedgerInterface $ledger,
    ) {
        $this->firstPartyObtain = \Closure::bind(
            static function (EntityBase $source, string $name): mixed {
                $values = $source->valueContainer->rawValues();
                $raw = array_key_exists($name, $values) ? $values[$name] : null;
                if (isset($source->casts[$name])) {
                    return $source->valueCaster()->castIn($name, $raw, $source->casts[$name]);
                }

                return $raw;
            },
            null,
            EntityBase::class,
        );
    }

    public function read(
        PrivilegedFieldReadCapability $capability,
        CapabilityExecutionBoundary $boundary,
        EntityInterface $entity,
        string $field,
    ): mixed {
        return $this->readMany($capability, $boundary, $entity, [$field])[$field];
    }

    /**
     * Reserve one strict ledger entry for an explicit related field set, then
     * obtain every value. Unrelated ambient reads are never batched.
     *
     * @param list<string> $fields Runtime validation narrows this to non-empty.
     * @return array<string, mixed>
     */
    public function readMany(
        PrivilegedFieldReadCapability $capability,
        CapabilityExecutionBoundary $boundary,
        EntityInterface $entity,
        array $fields,
        ?CapabilityReason $requiredReason = null,
    ): array {
        $authorization = $this->capabilities->authorizationFor($capability, $boundary);
        if ($authorization === null) {
            throw new FieldReadDenied('A live registered field-read capability is required.');
        }

        $declaration = $authorization->declaration;
        if ($requiredReason !== null && $declaration->reason !== $requiredReason) {
            throw new FieldReadDenied(sprintf('This closed reader requires the %s capability reason.', $requiredReason->value));
        }
        $entityType = $entity->getEntityTypeId();
        // V2 hydration attaches canonical structural selectors independently
        // of content fields. A legacy `bundle` value may be absent/empty and
        // must never weaken or spuriously reject audited authorization.
        $bundle = $entity instanceof EntityBase && $entity->_hasEntityStructure()
            ? $entity->entityStructure()->bundleId
            : $entity->bundle();
        if ($fields === [] || array_values(array_unique($fields)) !== $fields) {
            throw new \InvalidArgumentException('Audited multi-field reads require a non-empty unique field list.');
        }
        $outsideDeclaration = array_diff($fields, $declaration->fields);
        if (!$declaration->wildcard && (
            !in_array($entityType, $declaration->entityTypes, true)
            || !in_array($bundle, $declaration->bundles, true)
            || $outsideDeclaration !== []
        )) {
            throw new FieldReadDenied(sprintf(
                'Capability issuer "%s" does not grant the requested %s fields for bundle %s.',
                $declaration->issuer,
                $entityType,
                $bundle,
            ));
        }

        $context = $authorization->context;
        $receipt = $this->ledger->reserve(new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Value,
            reason: $declaration->reason,
            issuer: $declaration->issuer,
            actorSemantics: $context->actorSemantics,
            actorId: $context->actorId,
            entityTypeId: $entityType,
            entityId: $entity->id(),
            fields: $fields,
            bundles: [$bundle],
            tenantId: $context->tenantId,
            communityId: $context->communityId,
            queryFingerprint: null,
            queryOperations: [],
            classificationGeneration: $context->classificationGeneration,
            policyGeneration: $context->policyGeneration,
            correlationId: $context->executionBoundary,
            callSite: $this->callSite('readMany'),
        ));

        try {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $this->obtainReservedValue($entity, $field);
            }
        } catch (\Throwable $e) {
            $this->ledger->finalize($receipt, PrivilegedReadOutcome::Failed);
            throw $e;
        }

        $this->ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);

        return $values;
    }

    /**
     * Activation-compatible first-party obtain path. The raw-value closure is
     * created and retained only inside this audited reader; no callable or raw
     * bag is exposed to entities, repositories, or application code.
     */
    private function obtainReservedValue(EntityInterface $entity, string $field): mixed
    {
        if (!$entity instanceof EntityBase) {
            return $entity->get($field);
        }

        return ($this->firstPartyObtain)($entity, $field);
    }

    private function callSite(string $method): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (($frame['class'] ?? null) !== self::class && isset($frame['file'], $frame['line'])) {
                return $frame['file'] . ':' . $frame['line'];
            }
        }

        return self::class . '::' . $method;
    }
}
