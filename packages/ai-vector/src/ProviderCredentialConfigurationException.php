<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/** @api Non-sensitive refusal for raw or incomplete provider credential configuration. */
final class ProviderCredentialConfigurationException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('[PROVIDER_CREDENTIAL_CONFIGURATION_REFUSED] ' . $reason);
    }
}
