<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/**
 * Framework-neutral scope owner. The injected resolver validates an actor or
 * system declaration and returns a closeable installation. Cleanup always
 * precedes queue acknowledgement, release, failure persistence, or replay.
 *
 * @api
 */
final readonly class ScopedQueueAuthorityRuntime implements QueueAuthorityRuntimeInterface
{
    /** @var \Closure(QueueEnvelopeV1): QueueAuthorityScopeInterface */
    private \Closure $scopeResolver;

    /** @param callable(QueueEnvelopeV1): QueueAuthorityScopeInterface $scopeResolver */
    public function __construct(callable $scopeResolver)
    {
        $this->scopeResolver = \Closure::fromCallable($scopeResolver);
    }

    public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
    {
        $scope = ($this->scopeResolver)($envelope);
        try {
            return $handler();
        } finally {
            $scope->close();
        }
    }
}
