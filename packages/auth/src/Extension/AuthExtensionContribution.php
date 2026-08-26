<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** One provider's optional ownership of the exclusive auth extension slots. @api */
final readonly class AuthExtensionContribution
{
    public function __construct(
        public ?RegistrationPolicyInterface $registration = null,
        public ?RegistrationProfileHandlerInterface $profile = null,
        public ?AuthRedirectPolicyInterface $redirect = null,
        public ?AuthMailContentPolicyInterface $mail = null,
        public ?InitialRolePolicyInterface $initialRoles = null,
    ) {}
}
