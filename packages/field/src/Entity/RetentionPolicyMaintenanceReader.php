<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Entity;

use Waaseyaa\Entity\EntityBase;

/** Closed, fixed-shape reader for retention scheduler configuration. @internal */
final class RetentionPolicyMaintenanceReader
{
    /** @var \Closure(RetentionPolicy): array<string, mixed> */
    private readonly \Closure $valueAuthority;

    public function __construct()
    {
        $this->valueAuthority = \Closure::bind(
            static fn(RetentionPolicy $policy): array => $policy->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(RetentionPolicy $policy): RetentionPolicyMaintenanceView
    {
        $values = ($this->valueAuthority)($policy);

        return new RetentionPolicyMaintenanceView(
            name: (string) ($values['name'] ?? ''),
            appliesTo: $this->normalizeStringList($values['applies_to'] ?? []),
            action: (string) ($values['action'] ?? ''),
            triggerKind: (string) ($values['trigger_kind'] ?? ''),
            triggerValue: (string) ($values['trigger_value'] ?? ''),
            exemptions: $this->normalizeStringList($values['exemptions'] ?? []),
            createdAt: isset($values['created_at']) ? (int) $values['created_at'] : null,
        );
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $raw): array
    {
        $decoded = is_string($raw) ? (json_decode($raw, true) ?? []) : $raw;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }
}
