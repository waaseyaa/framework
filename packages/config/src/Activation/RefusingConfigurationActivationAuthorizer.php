<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

final class RefusingConfigurationActivationAuthorizer implements ConfigurationActivationAuthorizerInterface
{
    public function authorize(ConfigurationActivationRequest $request, bool $deletes): void
    {
        throw new \DomainException(
            'Configuration activation is not authorized; bind ConfigurationActivationAuthorizerInterface with the required permission policy.',
        );
    }
}
