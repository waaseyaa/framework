<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Authentication;

/** Raised before an ineligible User can mutate authenticated session state. @api */
final class AuthenticationEligibilityException extends \RuntimeException {}
