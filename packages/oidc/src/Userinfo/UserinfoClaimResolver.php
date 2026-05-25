<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Userinfo;

/**
 * Maps OIDC scopes to claims, and OIDC claims to User entity field names.
 *
 * Field mapping:
 *   OIDC claim           → User entity field
 *   sub                  → (uid — always the entity id)
 *   name                 → name (the display name / label key)
 *   preferred_username   → name (User has no separate username column)
 *   updated_at           → created (closest available; no separate updated_at)
 *   email                → mail
 *   email_verified       → email_verified
 *   address              → (not stored; omitted)
 *   phone_number         → (not stored; omitted)
 *   phone_number_verified → (not stored; omitted)
 *
 * Fields without a User entity backing are silently omitted; the
 * field-access check has nothing to query so they never appear.
 *
 * @api
 */
final class UserinfoClaimResolver
{
    /**
     * Scope → claim names per OIDC Core §5.4.
     *
     * @var array<string, list<string>>
     */
    private const SCOPE_CLAIMS = [
        'openid' => ['sub'],
        'profile' => ['name', 'preferred_username', 'updated_at'],
        'email' => ['email', 'email_verified'],
        'address' => ['address'],
        'phone' => ['phone_number', 'phone_number_verified'],
    ];

    /**
     * OIDC claim → User entity field name.
     * Claims not present here have no backing field and will be omitted.
     *
     * @var array<string, string>
     */
    private const CLAIM_TO_FIELD = [
        'name' => 'name',
        'preferred_username' => 'name',
        'updated_at' => 'created',
        'email' => 'mail',
        'email_verified' => 'email_verified',
    ];

    /**
     * Returns the deduplicated union of claims for the given scopes.
     *
     * @param list<string> $grantedScopes
     * @return list<string>
     */
    public function claimsFor(array $grantedScopes): array
    {
        $claims = [];
        foreach ($grantedScopes as $scope) {
            foreach (self::SCOPE_CLAIMS[$scope] ?? [] as $claim) {
                $claims[$claim] = true;
            }
        }

        return array_keys($claims);
    }

    /**
     * Returns the User entity field name backing a given OIDC claim.
     * Returns null when no field backs the claim (e.g. 'sub', 'address').
     */
    public function fieldNameForClaim(string $claim): ?string
    {
        return self::CLAIM_TO_FIELD[$claim] ?? null;
    }

    /**
     * Human-readable scope description shown on the consent screen.
     */
    public function scopeDescriptionFor(string $scope): string
    {
        return match ($scope) {
            'openid' => 'Your unique user identifier',
            'profile' => 'Your name and profile information',
            'email' => 'Your email address',
            'address' => 'Your postal address',
            'phone' => 'Your phone number',
            default => $scope,
        };
    }
}
