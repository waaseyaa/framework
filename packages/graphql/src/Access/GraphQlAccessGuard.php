<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Access;

use GraphQL\Error\UserError;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * Wraps EntityAccessHandler for GraphQL resolver use.
 *
 * Entity-level denials throw UserError (maps to GraphQL errors[]).
 * Field-level uses open-by-default semantics (!isForbidden).
 */
final class GraphQlAccessGuard
{
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    public function __construct(
        private readonly EntityAccessHandler $handler,
        private readonly AccountInterface $account,
    ) {}

    public function canView(EntityInterface $entity): bool
    {
        return $this->handler->check($entity, 'view', $this->account)->isAllowed();
    }

    public function assertCreateAccess(string $entityTypeId, string $bundle = ''): void
    {
        $result = $this->handler->checkCreateAccess(
            $entityTypeId,
            $bundle !== '' ? $bundle : $entityTypeId,
            $this->account,
        );
        if (!$result->isAllowed()) {
            throw new UserError('Access denied: cannot create ' . $entityTypeId);
        }
    }

    public function assertUpdateAccess(EntityInterface $entity): void
    {
        if (!$this->handler->check($entity, 'update', $this->account)->isAllowed()) {
            throw new UserError('Access denied: cannot update entity');
        }
    }

    public function assertDeleteAccess(EntityInterface $entity): void
    {
        if (!$this->handler->check($entity, 'delete', $this->account)->isAllowed()) {
            throw new UserError('Access denied: cannot delete entity');
        }
    }

    /**
     * @param list<string> $fieldNames
     * @return list<string>
     */
    public function filterFields(EntityInterface $entity, array $fieldNames, string $operation = 'view'): array
    {
        return $this->handler->filterFields($entity, $fieldNames, $operation, $this->account);
    }

    public function assertFieldEditAccess(EntityInterface $entity, string $fieldName): void
    {
        if ($this->handler->checkFieldAccess($entity, $fieldName, 'edit', $this->account)->isForbidden()) {
            throw new UserError("Access denied: cannot edit field '{$fieldName}'");
        }
    }

    /**
     * True when the field is view-Forbidden for this entity (R14, audit A11).
     *
     * Used by {@see \Waaseyaa\GraphQL\Resolver\EntityResolver::resolveList()} to
     * exclude, value-independently, a row whose caller-supplied filter/sort
     * field the account may not READ — otherwise `total` and the item set leak a
     * presence/ordering oracle for a classification/clearance field that
     * `canView()` (entity-level) admits. Field access is per-entity, so this
     * must be evaluated per row, not once for the request.
     */
    public function isFieldViewForbidden(EntityInterface $entity, string $fieldName): bool
    {
        return $this->handler->checkFieldAccess($entity, $fieldName, 'view', $this->account)->isForbidden();
    }
}
