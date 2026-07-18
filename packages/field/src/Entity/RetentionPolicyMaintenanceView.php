<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Entity;

/** Fixed-shape retention configuration used by system maintenance jobs. @internal */
final readonly class RetentionPolicyMaintenanceView
{
    /**
     * @param list<string> $appliesTo
     * @param list<string> $exemptions
     */
    public function __construct(
        public string $name,
        public array $appliesTo,
        public string $action,
        public string $triggerKind,
        public string $triggerValue,
        public array $exemptions,
        public ?int $createdAt,
    ) {}

    /**
     * Stable operational metadata attached to maintenance-job outcomes.
     *
     * @return array{policy_name: string, action: string, trigger_kind: string, created_at: int|null}
     */
    public function auditContext(): array
    {
        return [
            'policy_name' => $this->name,
            'action' => $this->action,
            'trigger_kind' => $this->triggerKind,
            'created_at' => $this->createdAt,
        ];
    }

    public function matchesLabel(string $labelId): bool
    {
        foreach ($this->appliesTo as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                if ($prefix === '' || str_starts_with($labelId, $prefix)) {
                    return true;
                }
            } elseif ($pattern === $labelId) {
                return true;
            }
        }

        return false;
    }

    public function isExempt(string $entityType, string $uuid): bool
    {
        return in_array($entityType . ':' . $uuid, $this->exemptions, true);
    }
}
