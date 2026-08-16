<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @api Stable non-sensitive guarded-consumption refusal codes. */
enum SecretConsumptionCode: string
{
    case RegistryNotFrozen = 'SECRET_CONSUMER_REGISTRY_NOT_FROZEN';
    case ConsumerNotRegistered = 'SECRET_CONSUMER_NOT_REGISTERED';
    case ConsumerMismatch = 'SECRET_CONSUMER_MISMATCH';
    case AuthenticationFailed = 'SECRET_CONSUMER_AUTHENTICATION_FAILED';
    case ConsumerFailure = 'SECRET_CONSUMER_FAILURE';
}
