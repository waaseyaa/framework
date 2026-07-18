<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Bootstrap;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Access\Query\QueryFieldReadRequest;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Audit\AuditedQueryFieldRead;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/** Executes the exact pre-authentication User identity query under audit. @api */
final readonly class AuditedUserIdentityLookup implements UserIdentityLookupInterface
{
    public function __construct(
        private AuditedQueryFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration = 'wp4-user-query-v1',
        private string $policyGeneration = 'wp4-user-query-v1',
    ) {}

    public function findActiveByLogin(EntityRepositoryInterface $repository, string $login): ?EntityInterface
    {
        $boundary = $this->capabilities->openBoundary(bin2hex(random_bytes(16)));
        $capability = $this->capabilities->issueQueryRead('user.identity-lookup', new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
        try {
            $id = $this->execute($repository, $login, 'name', $capability, $boundary);
            $id ??= $this->execute($repository, $login, 'mail', $capability, $boundary);

            return $id === null ? null : $repository->find((string) $id);
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }

    public function mailExists(EntityRepositoryInterface $repository, string $mail): bool
    {
        $boundary = $this->capabilities->openBoundary(bin2hex(random_bytes(16)));
        $capability = $this->capabilities->issueQueryRead('user.identity-lookup', new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
        try {
            $request = QueryFieldReadRequest::fromShape(
                entityTypeId: 'user',
                bundles: ['user'],
                fields: ['mail'],
                operations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
                normalizedShape: ['predicates' => [['mail', '=', '?']], 'range' => [0, 1]],
            );
            $reservation = $this->reader->reserve($capability, $boundary, $request);
            try {
                $ids = $repository->getQuery()->accessCheck(false)->condition('mail', $mail)->range(0, 1)->execute();
            } catch (\Throwable $exception) {
                $reservation->failed();
                throw $exception;
            }
            $reservation->succeeded();

            return $ids !== [];
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }

    private function execute(
        EntityRepositoryInterface $repository,
        string $login,
        string $identityField,
        \Waaseyaa\Access\Capability\QueryFieldReadCapability $capability,
        \Waaseyaa\Access\Capability\CapabilityExecutionBoundary $boundary,
    ): int|string|null {
        $request = QueryFieldReadRequest::fromShape(
            entityTypeId: 'user',
            bundles: ['user'],
            fields: [$identityField, 'status'],
            operations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
            normalizedShape: [
                'predicates' => [[$identityField, '=', '?'], ['status', '=', '?']],
                'range' => [0, 1],
            ],
        );
        $reservation = $this->reader->reserve($capability, $boundary, $request);
        try {
            $ids = $repository->getQuery()
                ->accessCheck(false)
                ->condition($identityField, $login)
                ->condition('status', 1)
                ->range(0, 1)
                ->execute();
        } catch (\Throwable $exception) {
            $reservation->failed();
            throw $exception;
        }
        $reservation->succeeded();

        $id = reset($ids);

        return is_int($id) || is_string($id) ? $id : null;
    }
}
