<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @api Stable non-sensitive secret-resolution refusal codes. */
enum SecretResolutionCode: string
{
    case RegistryNotFrozen = 'SECRET_REGISTRY_NOT_FROZEN';
    case ProviderUnknown = 'SECRET_PROVIDER_UNKNOWN';
    case PolicyDenied = 'SECRET_POLICY_DENIED';
    case ProviderFailure = 'SECRET_PROVIDER_FAILURE';
    case ClassMismatch = 'SECRET_CLASS_MISMATCH';
}
