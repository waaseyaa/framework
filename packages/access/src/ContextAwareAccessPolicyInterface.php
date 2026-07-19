<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Companion interface for access policies that need additional context.
 *
 * Standard {@see AccessPolicyInterface::access()} only receives (entity, operation, account).
 * Some operations — notably 'translate' — need extra context (e.g. the langcode being
 * translated to) to make a correct decision. Policies that implement this interface
 * receive that context bag in addition to the standard arguments.
 *
 * EntityAccessHandler::check() prefers accessWithContext() when the policy implements
 * this interface; otherwise it falls back to AccessPolicyInterface::access().
 *
 * @api
 */
interface ContextAwareAccessPolicyInterface
{
    /**
     * Check access for an existing entity with additional context.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $operation The operation: 'view', 'update', 'delete', or 'translate'.
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     * @param array<string, mixed> $context Extra context. For 'translate':
     *                                       ['langcode' => string].
     */
    public function accessWithContext(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
        array $context,
    ): AccessResult;
}
