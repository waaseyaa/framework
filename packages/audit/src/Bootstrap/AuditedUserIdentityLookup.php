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
            $id ??= $this->execute($repository, $login, 'mail', $capability, $boundary, caseInsensitive: true);

            return $id === null ? null : $repository->find((string) $id);
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }

    /**
     * Resolve the single active User that owns a recovery mail address.
     *
     * Recovery is not the login namespace, so this never probes an exact
     * spelling first. The canonical bounded query is the only probe: an
     * upgraded database holding active case-variant duplicates fails closed
     * instead of handing recovery to whichever row happened to match the
     * submitted spelling exactly. `findActiveByLogin()` keeps its exact-first
     * legacy compatibility deliberately; that ladder is not shared here.
     */
    public function findActiveByMail(EntityRepositoryInterface $repository, string $mail): ?EntityInterface
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
            $id = $this->execute($repository, $mail, 'mail', $capability, $boundary, caseInsensitive: true);

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
                normalizedShape: ['predicates' => [['mail', 'CASE_INSENSITIVE_EQUALS', '?']], 'range' => [0, 1]],
            );
            $reservation = $this->reader->reserve($capability, $boundary, $request);
            try {
                $ids = $repository->getQuery()
                    ->accessCheck(false)
                    ->condition('mail', $mail, 'CASE_INSENSITIVE_EQUALS')
                    ->range(0, 1)
                    ->execute();
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
        bool $caseInsensitive = false,
    ): int|string|null {
        $request = QueryFieldReadRequest::fromShape(
            entityTypeId: 'user',
            bundles: ['user'],
            fields: [$identityField, 'status'],
            operations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
            normalizedShape: [
                'predicates' => [
                    [$identityField, $caseInsensitive ? 'CASE_INSENSITIVE_EQUALS' : '=', '?'],
                    ['status', '=', '?'],
                ],
                'range' => [0, $caseInsensitive ? 2 : 1],
            ],
        );
        $reservation = $this->reader->reserve($capability, $boundary, $request);
        try {
            $query = $repository->getQuery()->accessCheck(false);
            $query = $caseInsensitive
                ? $query->condition($identityField, $login, 'CASE_INSENSITIVE_EQUALS')
                : $query->condition($identityField, $login);
            $ids = $query
                ->condition('status', 1)
                ->range(0, $caseInsensitive ? 2 : 1)
                ->execute();
        } catch (\Throwable $exception) {
            $reservation->failed();
            throw $exception;
        }
        $reservation->succeeded();

        if ($caseInsensitive && count($ids) !== 1) {
            // An upgraded database may contain historical case-variant
            // duplicates. Never select an arbitrary credential identity.
            return null;
        }

        $id = reset($ids);

        return is_int($id) || is_string($id) ? $id : null;
    }
}
