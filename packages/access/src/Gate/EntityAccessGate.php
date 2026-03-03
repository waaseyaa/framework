<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * Adapter that bridges GateInterface to EntityAccessHandler.
 *
 * Translates gate ability checks into EntityAccessHandler calls:
 * - Entity subject → check($entity, $ability, $account)
 * - String subject + "create" → checkCreateAccess($entityTypeId, '', $account)
 * - String subject + other ability → denied (instance required)
 */
final class EntityAccessGate implements GateInterface
{
    public function __construct(
        private readonly EntityAccessHandler $handler,
    ) {}

    public function allows(string $ability, mixed $subject, ?object $user = null): bool
    {
        if (!$user instanceof AccountInterface) {
            error_log(sprintf(
                '[Waaseyaa] EntityAccessGate: expected AccountInterface, got %s for ability "%s".',
                get_debug_type($user),
                $ability,
            ));
            return false;
        }

        if ($subject instanceof EntityInterface) {
            try {
                return $this->handler->check($subject, $ability, $user)->isAllowed();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[Waaseyaa] EntityAccessGate: policy check threw %s for ability "%s" on %s: %s',
                    $e::class,
                    $ability,
                    $subject->getEntityTypeId(),
                    $e->getMessage(),
                ));
                return false;
            }
        }

        if (is_string($subject) && $ability === 'create') {
            // @todo Bundle is not conveyed through GateInterface; empty string used.
            // When bundle-aware create policies are added, this adapter will need
            // richer subject structures (e.g., ['entity_type' => 'node', 'bundle' => 'article']).
            try {
                return $this->handler->checkCreateAccess($subject, '', $user)->isAllowed();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[Waaseyaa] EntityAccessGate: create access check threw %s for ability "%s" on "%s": %s',
                    $e::class,
                    $ability,
                    $subject,
                    $e->getMessage(),
                ));
                return false;
            }
        }

        // This adapter only handles EntityInterface and string-typed create checks.
        error_log(sprintf(
            '[Waaseyaa] EntityAccessGate: unsupported subject type %s for ability "%s"; denying.',
            get_debug_type($subject),
            $ability,
        ));
        return false;
    }

    public function denies(string $ability, mixed $subject, ?object $user = null): bool
    {
        return !$this->allows($ability, $subject, $user);
    }

    public function authorize(string $ability, mixed $subject, ?object $user = null): void
    {
        if ($this->denies($ability, $subject, $user)) {
            throw new AccessDeniedException(ability: $ability, subject: $subject);
        }
    }
}
