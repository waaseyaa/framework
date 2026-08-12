<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

final class RequiredCapabilityUnavailableException extends \RuntimeException
{
    public const string ERROR_CODE = 'foundation.capability.unavailable';
}
