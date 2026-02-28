<?php

declare(strict_types=1);

namespace Aurora\Access\Gate;

/**
 * Determines whether a user is authorized to perform a given ability on a subject.
 *
 * The Gate resolves the appropriate policy for the subject and delegates
 * the ability check to the matching policy method.
 */
interface GateInterface
{
    /**
     * Determine if the given ability is allowed for the user on the subject.
     *
     * @param string  $ability The ability to check (e.g. 'view', 'update', 'delete').
     * @param mixed   $subject The subject being acted upon (typically an entity or entity type string).
     * @param ?object $user    The user performing the action. Null means the current/anonymous user.
     */
    public function allows(string $ability, mixed $subject, ?object $user = null): bool;

    /**
     * Determine if the given ability is denied for the user on the subject.
     *
     * @param string  $ability The ability to check.
     * @param mixed   $subject The subject being acted upon.
     * @param ?object $user    The user performing the action.
     */
    public function denies(string $ability, mixed $subject, ?object $user = null): bool;

    /**
     * Authorize the given ability for the user on the subject, or throw.
     *
     * @param string  $ability The ability to check.
     * @param mixed   $subject The subject being acted upon.
     * @param ?object $user    The user performing the action.
     *
<<<<<<< HEAD
     * @throws \RuntimeException If the ability is not allowed.
=======
     * @throws AccessDeniedException If the ability is not allowed.
>>>>>>> unit-9-access-gate
     */
    public function authorize(string $ability, mixed $subject, ?object $user = null): void;
}
