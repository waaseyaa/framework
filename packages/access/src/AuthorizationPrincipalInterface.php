<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Immutable authorization claims used by protected field-read policies.
 *
 * @api
 */
interface AuthorizationPrincipalInterface extends AccountInterface
{
    public function claimsGeneration(): string;

    public function tenantId(): ?string;

    public function communityId(): ?string;
}
