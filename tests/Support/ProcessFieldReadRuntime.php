<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;

/**
 * Test-only reset of the process-wide field-read runtime that AbstractKernel
 * installs during boot. Production workers keep one kernel and one registry;
 * PHPUnit reuses the process across unrelated tests.
 */
final class ProcessFieldReadRuntime
{
    public static function reset(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        ContentEntityBase::setEntityTypeManager(null);
        EntityReadRuntime::installGuard(null);
    }
}
