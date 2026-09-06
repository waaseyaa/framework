<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Exception;

/**
 * A persistent worker exhausted its handler roster without executing a message.
 */
final class UnhandledQueueMessage extends \RuntimeException
{
    public function __construct(object $message)
    {
        parent::__construct(sprintf(
            'No queue handler supports message type "%s".',
            $message::class,
        ));
    }
}
