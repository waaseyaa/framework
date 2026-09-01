<?php

declare(strict_types=1);

namespace Waaseyaa\User\Authentication;

/** The explicit boundary at which a User may become authenticated. @api */
enum AuthenticationStage: string
{
    case Registration = 'registration';
    case PasswordLogin = 'password_login';
    case DirectLogin = 'direct_login';
    case TwoFactorPromotion = 'two_factor_promotion';
    case ExistingSession = 'existing_session';
    case BearerResolution = 'bearer_resolution';
}
