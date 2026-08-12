<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

final class RefusingConfigurationCandidateSweepAuthorizer implements ConfigurationCandidateSweepAuthorizerInterface
{
    public function authorize(ConfigurationCandidateSweepRequest $request): void
    {
        throw new \DomainException('Configuration candidate maintenance requires a verified lease and fence.');
    }
}
