<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Fixtures;

use Waaseyaa\Queue\Attribute\UniqueJob;
use Waaseyaa\Queue\Job;

/**
 * Test fixture: a job that carries #[UniqueJob].
 *
 * Used by DbalQueueTest to verify that the persistent driver logs a warning
 * when dispatching a job annotated with an attribute it cannot enforce.
 */
#[UniqueJob]
final class UniqueJobMessage extends Job
{
    public function handle(): void {}
}
