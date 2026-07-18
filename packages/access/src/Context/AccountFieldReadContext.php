<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Entity\EntityInterface;

/**
 * Opaque identity for one installed account authority frame.
 *
 * The object is allocated when a scope is entered, never on a field read. Its
 * identity is therefore a cheap cache-generation token that cannot survive a
 * nested or later account context accidentally.
 *
 * @internal
 * @api Activation-ready account read frame returned by AccountFieldReadScope.
 */
final class AccountFieldReadContext
{
    /** @var array<int, array{subject: object, fields: array<string, bool>}> */
    private array $decisions = [];

    private ?EntityInterface $hotEntity = null;
    private ?CompiledFieldReadRule $hotRule = null;

    public function __construct(
        public readonly AuthorizationPrincipalInterface $principal,
        public readonly string $classificationGeneration,
        public readonly string $policyGeneration,
    ) {
        if ($classificationGeneration === '' || $policyGeneration === '') {
            throw new \InvalidArgumentException('Field-read context generations cannot be empty.');
        }
    }

    public function decision(object $subject, string $field): ?bool
    {
        $entry = $this->decisions[spl_object_id($subject)] ?? null;
        if ($entry === null || $entry['subject'] !== $subject) {
            return null;
        }

        return $entry['fields'][$field] ?? null;
    }

    public function remember(object $subject, string $field, bool $allowed): void
    {
        $id = spl_object_id($subject);
        $entry = $this->decisions[$id] ?? ['subject' => $subject, 'fields' => []];
        $entry['fields'][$field] = $allowed;
        $this->decisions[$id] = $entry;
        if ($allowed && $subject instanceof EntityInterface) {
            $this->hotEntity = $subject;
        }
    }

    public function hotAllows(EntityInterface $entity, CompiledFieldReadRule $rule): bool
    {
        return $this->hotEntity === $entity && $this->hotRule === $rule;
    }

    public function rememberHotRule(CompiledFieldReadRule $rule): void
    {
        $this->hotRule = $rule;
    }

    public function invalidate(object $subject): void
    {
        $id = spl_object_id($subject);
        if (($this->decisions[$id]['subject'] ?? null) === $subject) {
            unset($this->decisions[$id]);
        }
        if ($this->hotEntity === $subject) {
            $this->hotEntity = null;
            $this->hotRule = null;
        }
    }
}
