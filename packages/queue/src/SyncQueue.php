<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;

/**
 * Inline queue for request-local execution.
 *
 * Handler exceptions deliberately propagate to the dispatching caller. Sync
 * mode has no worker boundary and therefore does not create failed-job rows;
 * callers own transaction rollback, logging, and retry policy.
 */
final class SyncQueue implements QueueInterface
{
    /** @var HandlerInterface[] */
    private readonly array $handlers;

    private readonly AttributeGuard $guard;

    /**
     * @param HandlerInterface[] $handlers
     *   Additional handlers. A JobHandler is always prepended so that any
     *   Job subclass dispatched without a dedicated adapter has its handle()
     *   method invoked automatically.
     * @param AttributeGuard|null $guard
     *   Attribute enforcement guard. A default instance is created when null.
     */
    public function __construct(
        array $handlers = [],
        ?AttributeGuard $guard = null,
        private readonly ?QueuePayloadDeprecationDiagnostic $payloadDiagnostic = null,
    ) {
        $this->handlers = [new JobHandler(), ...$handlers];
        $this->guard = $guard ?? new AttributeGuard();
    }

    public function dispatch(object $message): void
    {
        $this->payloadDiagnostic?->inspect($message);
        if (!$this->guard->allows($message)) {
            return;
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($message)) {
                $handler->handle($message);
            }
        }
    }
}
