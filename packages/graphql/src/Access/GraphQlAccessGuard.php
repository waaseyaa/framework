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
}
