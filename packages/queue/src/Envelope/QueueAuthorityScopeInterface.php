<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** One handler-only account/capability installation. @api */
interface QueueAuthorityScopeInterface
{
    public function close(): void;
}
