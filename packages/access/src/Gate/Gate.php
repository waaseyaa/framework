<?php

declare(strict_types=1);

namespace Aurora\Access\Gate;

/**
 * Resolves policies by convention and delegates ability checks.
 *
 * Policy resolution strategy:
 * 1. If a policy has a #[PolicyAttribute] with a matching entityType, it is used.
 * 2. Otherwise, a naming convention is applied: for entity type "node", the Gate
 *    looks for a policy class whose short name is "NodePolicy" (PascalCase of the
 *    entity type ID + "Policy").
 *
 * Once a policy is resolved, the Gate calls the method matching the ability name.
 * For example, allows('update', $node) calls $policy->update($user, $node).
 * If the method does not exist on the policy, the ability is denied.
 */
final class Gate implements GateInterface
{
    /**
     * Resolved policy instances keyed by entity type.
     *
     * @var array<string, object>
     */
    private array $resolvedPolicies = [];

    /**
     * @param object[] $policies Policy instances (or class-string[] to instantiate).
     */
    public function __construct(
        private readonly array $policies = [],
    ) {
        $this->indexPolicies();
    }

    public function allows(string $ability, mixed $subject, ?object $user = null): bool
    {
        $policy = $this->resolvePolicy($subject);

        if ($policy === null) {
            return false;
        }

        if (!method_exists($policy, $ability)) {
            return false;
        }

        return (bool) $policy->{$ability}($user, $subject);
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

    /**
     * Index all policies by their entity type for fast lookup.
     */
    private function indexPolicies(): void
    {
        foreach ($this->policies as $policy) {
            $entityType = $this->detectEntityType($policy);

            if ($entityType !== null) {
                $this->resolvedPolicies[$entityType] = $policy;
            }
        }
    }

    /**
     * Detect the entity type a policy applies to.
     *
     * First checks for a #[PolicyAttribute], then falls back to naming convention.
     */
    private function detectEntityType(object $policy): ?string
    {
        $reflection = new \ReflectionClass($policy);

        // Check for PolicyAttribute.
        $attributes = $reflection->getAttributes(PolicyAttribute::class);

        if ($attributes !== []) {
            /** @var PolicyAttribute $attr */
            $attr = $attributes[0]->newInstance();

            return $attr->entityType;
        }

        // Fall back to naming convention: "NodePolicy" -> "node".
        $shortName = $reflection->getShortName();

        if (str_ends_with($shortName, 'Policy')) {
            $typePart = substr($shortName, 0, -6); // Remove "Policy" suffix.

            return $this->toSnakeCase($typePart);
        }

        return null;
    }

    /**
     * Resolve the policy for the given subject.
     *
     * The subject can be an object with a getEntityTypeId() method (entity),
     * or a string representing the entity type ID directly.
     */
    private function resolvePolicy(mixed $subject): ?object
    {
        $entityType = $this->extractEntityType($subject);

        if ($entityType === null) {
            return null;
        }

        return $this->resolvedPolicies[$entityType] ?? null;
    }

    /**
     * Extract the entity type ID from the subject.
     */
    private function extractEntityType(mixed $subject): ?string
    {
        if (is_string($subject)) {
            return $subject;
        }

        if (is_object($subject) && method_exists($subject, 'getEntityTypeId')) {
            return $subject->getEntityTypeId();
        }

        return null;
    }

    /**
     * Convert PascalCase to snake_case.
     *
     * "NodeArticle" -> "node_article"
     * "Node" -> "node"
     * "TaxonomyTerm" -> "taxonomy_term"
     */
    private function toSnakeCase(string $value): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value);

        return strtolower((string) $result);
    }
}
