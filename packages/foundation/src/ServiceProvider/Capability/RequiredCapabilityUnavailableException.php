<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/** Stable capability-composition refusal surface. @api */
final class RequiredCapabilityUnavailableException extends \RuntimeException
{
    public const string ERROR_CODE = 'foundation.capability.unavailable';
}
