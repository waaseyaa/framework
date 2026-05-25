<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use Waaseyaa\Oidc\Keys\SigningKey;

/**
 * Provides signing key material for JWT issuance and verification.
 *
 * WP01 ships InMemoryKeyMaterialProvider (file-backed, matches the existing
 * PemFileKeyLoader contract). WP04 replaces the binding with
 * RealKeyMaterialProvider (DB-backed SigningKeyRepository).
 *
 * @api
 */
interface KeyMaterialProviderInterface
{
    /**
     * The key to use for signing new tokens (the "current" key).
     */
    public function currentKey(): SigningKey;

    /**
     * All keys valid for verification: current + rotated-out-but-not-expired.
     *
     * @return list<SigningKey>
     */
    public function allActive(): array;
}
