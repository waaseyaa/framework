<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

final class RefusingConfigurationRollbackValidator implements ConfigurationRollbackValidatorInterface
{
    public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void
    {
        throw new \DomainException(
            'Configuration rollback is unavailable until a compatibility validator is bound.',
        );
    }
}
