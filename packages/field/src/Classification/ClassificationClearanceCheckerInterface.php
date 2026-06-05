<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Access\AccountInterface;

/**
 * Resolves an account's classification clearance level.
 *
 * Clearance is an integer ordinal: the higher the level, the greater the
 * sensitivity an account may access. The {@see ClassificationFieldAccessPolicy}
 * compares the resolved clearance against the entity label's
 * {@see ClassificationLabelDefinition::getConfidentialityLevel()}; access is
 * forbidden when clearance is lower than the label's confidentiality.
 *
 * Implementations may compute clearance from roles, capabilities, attribute
 * claims, or any other account-derived signal. The default
 * {@see RoleBasedClearanceChecker} maps the account's roles through a
 * config-driven catalogue (`classification.role_clearance`).
 *
 * @api
 */
interface ClassificationClearanceCheckerInterface
{
    /**
     * Resolve the maximum classification clearance the account is granted.
     *
     * Returns 0 (no clearance) for anonymous accounts or for any account
     * whose roles fall outside the configured catalogue.
     */
    public function clearanceLevelFor(AccountInterface $account): int;
}
