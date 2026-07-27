<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Adapter that bridges GateInterface to EntityAccessHandler.
 *
 * Translates gate ability checks into EntityAccessHandler calls:
 * - Entity subject → check($entity, $ability, $account)
 * - String subject + "create" → checkCreateAccess($entityTypeId, '', $account)
 *   (bundle-less form, preserved for backward compatibility)
 * - array{entity_type: string, bundle: string} subject + "create" →
 *   checkCreateAccess($entityTypeId, $bundle, $account) (bundle-aware form)
 * - String subject + other ability → denied (instance required)
 */
final class EntityAccessGate implements GateInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param AccountFieldReadScopeInterface|null $fieldReadScope Carrier of the
     *        middleware-established immutable principal (FieldReadContextMiddleware).
     * @param AccountContextInterface|null $accountContext Request-scoped acting-account
     *        holder, consulted when no scope principal is installed.
     */
    public function __construct(
        private readonly EntityAccessHandler $handler,
        ?LoggerInterface $logger = null,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function allows(string $ability, mixed $subject, ?object $user = null): bool
    {
        // GateInterface contract: null means the current/anonymous user.
        $user ??= $this->resolveCurrentPrincipal();

        if (!$user instanceof AuthorizationPrincipalInterface) {
            $this->logger->warning(sprintf(
                'EntityAccessGate: expected AuthorizationPrincipalInterface, got %s for ability "%s".',
                get_debug_type($user),
                $ability,
            ));
            return false;
        }

        if ($subject instanceof EntityInterface) {
            try {
                return $this->handler->check($subject, $ability, $user)->isAllowed();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'EntityAccessGate: policy check threw %s for ability "%s" on %s: %s',
                    $e::class,
                    $ability,
                    $subject->getEntityTypeId(),
                    $e->getMessage(),
                ));
                return false;
            }
        }

        if (is_string($subject) && $ability === 'create') {
            try {
                return $this->handler->checkCreateAccess($subject, '', $user)->isAllowed();
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'EntityAccessGate: create access check threw %s for ability "%s" on "%s": %s',
                    $e::class,
                    $ability,
                    $subject,
                    $e->getMessage(),
                ));
                return false;
            }
        }

        if (is_array($subject) && $ability === 'create') {
            $entityType = $subject['entity_type'] ?? null;
            $bundle = $subject['bundle'] ?? null;

            if (is_string($entityType) && $entityType !== '' && is_string($bundle)) {
                try {
                    return $this->handler->checkCreateAccess($entityType, $bundle, $user)->isAllowed();
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'EntityAccessGate: create access check threw %s for ability "%s" on "%s"/"%s": %s',
                        $e::class,
                        $ability,
                        $entityType,
                        $bundle,
                        $e->getMessage(),
                    ));
                    return false;
                }
            }

            $this->logger->warning(sprintf(
                'EntityAccessGate: array subject for ability "%s" must be shaped as '
                    . '[\'entity_type\' => string, \'bundle\' => string]; denying.',
                $ability,
            ));
            return false;
        }

        // This adapter only handles EntityInterface and string-typed create checks.
        $this->logger->warning(sprintf(
            'EntityAccessGate: unsupported subject type %s for ability "%s"; denying.',
            get_debug_type($subject),
            $ability,
        ));
        return false;
    }

    public function denies(string $ability, mixed $subject, ?object $user = null): bool
    {
        return !$this->allows($ability, $subject, $user);
    }

    /**
     * Resolve the current principal: the immutable scope principal when one is
     * installed, otherwise a non-entity principal from the acting-account
     * context, otherwise the anonymous principal. Entity-backed accounts must
     * cross the audited principal factory and never reach a policy directly
     * (see DecisionAccountResolver), so they fall through to anonymous here.
     */
    private function resolveCurrentPrincipal(): AuthorizationPrincipalInterface
    {
        $principal = $this->fieldReadScope?->current();
        if ($principal !== null) {
            return $principal;
        }

        $account = $this->accountContext?->current();
        if ($account instanceof AuthorizationPrincipalInterface && !$account instanceof EntityInterface) {
            return $account;
        }

        return new AuthorizationPrincipal(0, false, [], [], 'anonymous');
    }

    public function authorize(string $ability, mixed $subject, ?object $user = null): void
    {
        if ($this->denies($ability, $subject, $user)) {
            throw new AccessDeniedException(ability: $ability, subject: $subject);
        }
    }
}
