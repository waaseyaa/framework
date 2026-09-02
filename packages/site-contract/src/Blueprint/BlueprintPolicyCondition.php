<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintPolicyCondition
{
    /** @param list<string>|null $states */
    public function __construct(
        public BlueprintConditionKind $kind,
        public ?string $permission = null,
        public ?array $states = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['kind' => $this->kind->value];
        if ($this->permission !== null) {
            $result['permission'] = $this->permission;
        }
        if ($this->states !== null) {
            $result['states'] = $this->states;
        }

        return $result;
    }
}
