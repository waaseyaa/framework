<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Access\AccountInterface;

/**
 * Default {@see ClassificationClearanceCheckerInterface} implementation that
 * resolves an account's clearance from its roles using a configurable mapping.
 *
 * The mapping is supplied at construction time as `role => level` pairs and is
 * sourced from the `classification.role_clearance` config key (FR-006). Stock
 * default mapping:
 *
 *   admin            => 10
 *   nation-steward   =>  9
 *   editor           =>  5
 *   viewer           =>  1
 *   anonymous        =>  0
 *
 * For an account carrying multiple roles, the resolved clearance is the
 * maximum across the roles that appear in the catalogue. Roles absent from
 * the catalogue contribute 0.
 *
 * @api
 */
final class RoleBasedClearanceChecker implements ClassificationClearanceCheckerInterface
{
    /**
     * Stock catalogue used when no role_clearance config has been provided.
     *
     * @var array<string, int>
     */
    public const array DEFAULT_ROLE_CLEARANCE = [
        'admin' => 10,
        'nation-steward' => 9,
        'editor' => 5,
        'viewer' => 1,
        'anonymous' => 0,
    ];

    /** @var array<string, int> */
    private readonly array $roleClearance;

    /**
     * @param array<array-key, mixed>|null $roleClearance role-id → clearance level.
     *                                                    Null uses {@see DEFAULT_ROLE_CLEARANCE}.
     *                                                    Numeric keys, empty keys, and non-scalar values are
     *                                                    silently dropped — config-driven mappings can drift.
     */
    public function __construct(?array $roleClearance = null)
    {
        $normalized = [];
        foreach ($roleClearance ?? self::DEFAULT_ROLE_CLEARANCE as $role => $level) {
            if (!is_string($role) || $role === '') {
                continue;
            }
            if (!is_int($level) && !is_string($level)) {
                continue;
            }
            $normalized[$role] = (int) $level;
        }
        $this->roleClearance = $normalized;
    }

    public function clearanceLevelFor(AccountInterface $account): int
    {
        $best = 0;
        foreach ($account->getRoles() as $role) {
            $level = $this->roleClearance[$role] ?? 0;
            if ($level > $best) {
                $best = $level;
            }
        }

        return $best;
    }
}
