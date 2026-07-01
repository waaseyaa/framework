<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Attribute\RateLimited;
use Waaseyaa\Queue\Job;

/**
 * Test fixture: a job that carries #[RateLimited].
 *
 * Used by DbalQueueTest to verify that the persistent driver logs a warning
 * when dispatching a job annotated with an attribute it cannot enforce.
 */
#[RateLimited]
final class RateLimitedMessage extends Job
{
    public function handle(): void {}
}
