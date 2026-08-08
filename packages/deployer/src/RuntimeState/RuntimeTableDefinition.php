<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/** @api */
final readonly class RuntimeTableDefinition
{
    /**
     * @param list<string> $accountReferenceColumns
     * @param list<int> $allowedAccountReferenceValues
     */
    public function __construct(
        public string $name,
        public RuntimeTablePolicy $policy,
        public array $accountReferenceColumns = [],
        public array $allowedAccountReferenceValues = [],
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Runtime table names must be canonical SQLite identifiers.');
        }
        foreach ($allowedAccountReferenceValues as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Allowed account-reference sentinels must be non-negative integers.');
            }
        }
    }
}
