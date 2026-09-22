<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/** @api */
final readonly class RuntimeTableDefinition
{
    /**
     * @param list<string> $accountReferenceColumns
     * @param list<int> $allowedAccountReferenceValues
     * @param list<string> $legacyBaseColumns
     * @param list<string> $additiveNullableTextColumns
     * @param array<string, string> $additiveUniqueIndexes Index name to column.
     */
    public function __construct(
        public string $name,
        public RuntimeTablePolicy $policy,
        public array $accountReferenceColumns = [],
        public array $allowedAccountReferenceValues = [],
        public array $legacyBaseColumns = [],
        public ?string $stableIdentityColumn = null,
        public array $additiveNullableTextColumns = [],
        public array $additiveUniqueIndexes = [],
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Runtime table names must be canonical SQLite identifiers.');
        }
        foreach ($allowedAccountReferenceValues as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Allowed account-reference sentinels must be non-negative integers.');
            }
        }
        if ($stableIdentityColumn !== null && preg_match('/^[a-z_][a-z0-9_]*$/', $stableIdentityColumn) !== 1) {
            throw new \InvalidArgumentException('Stable identity columns must be canonical SQLite identifiers.');
        }
        foreach ($additiveNullableTextColumns as $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Additive columns must be canonical SQLite identifiers.');
            }
        }
        foreach ($legacyBaseColumns as $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Legacy base columns must be canonical SQLite identifiers.');
            }
        }
        foreach ($additiveUniqueIndexes as $index => $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $index) !== 1 || preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Additive indexes and their columns must be canonical SQLite identifiers.');
            }
        }
        if ($additiveNullableTextColumns !== [] || $additiveUniqueIndexes !== []) {
            if ($stableIdentityColumn === null) {
                throw new \InvalidArgumentException('An additive schema transition requires a stable identity column.');
            }
            if ($legacyBaseColumns === []) {
                throw new \InvalidArgumentException('An additive schema transition requires an exact legacy base-column roster.');
            }
            if (!in_array($stableIdentityColumn, $legacyBaseColumns, true)) {
                throw new \InvalidArgumentException('The stable identity column must be part of the legacy base-column roster.');
            }
            foreach ($additiveUniqueIndexes as $column) {
                if (!in_array($column, $additiveNullableTextColumns, true)) {
                    throw new \InvalidArgumentException('Each additive unique index must target an additive column.');
                }
            }
        }
    }
}
