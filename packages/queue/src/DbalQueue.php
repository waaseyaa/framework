<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Queue implementation backed by a persistent transport.
 *
 * Serializes messages and pushes them to the transport for
 * later processing by a Worker.
 *
 * **Important — #[UniqueJob] / #[RateLimited] are NOT enforced by this driver.**
 * Both attributes are handled exclusively by {@see AttributeGuard}, which
 * performs pure in-process / per-PHP-process tracking. They are enforced by
 * {@see SyncQueue} (same process) but NOT by DbalQueue (the persistent
 * transport-backed driver). Cross-process enforcement would require a
 * distributed dedup/rate-limit store and is currently unimplemented.
 *
 * When a message carrying one of these attributes is dispatched, a warning
 * is logged once per job class per process so that the no-op is non-silent.
 * The message is still pushed to the transport.
 */
final class DbalQueue implements QueueInterface
{
    private readonly LoggerInterface $logger;

    /**
     * Per-process set of job class names already warned about unenforced attributes.
     * Prevents log spam when the same job class is dispatched multiple times.
     *
     * @var array<string, true>
     */
    private array $warnedJobClasses = [];

    /**
     * @param TransportInterface  $transport     Persistent transport backend.
     * @param string              $defaultQueue  Queue name used when no #[OnQueue] attribute is present.
     * @param LoggerInterface|null $logger        Optional logger for unenforced-attribute warnings.
     *                                            Defaults to NullLogger (silent). Existing callers
     *                                            `new DbalQueue($transport)` and
     *                                            `new DbalQueue($transport, 'queue')` remain valid.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $defaultQueue = 'default',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(object $message): void
    {
        $this->warnIfAttributeNotEnforced($message);

        $queue = $this->resolveQueue($message);
        $delay = $this->resolveDelay($message);
        $payload = serialize($message);

        $this->transport->push($queue, $payload, $delay);
    }

    private function resolveQueue(object $message): string
    {
        $ref = new \ReflectionClass($message);
        $attributes = $ref->getAttributes(Attribute\OnQueue::class);

        if ($attributes !== []) {
            $onQueue = $attributes[0]->newInstance();

            return $onQueue->name;
        }

        return $this->defaultQueue;
    }

    private function resolveDelay(object $message): int
    {
        if ($message instanceof Job && $message->isReleased()) {
            return $message->getReleaseDelay();
        }

        return 0;
    }

    /**
     * Emit a one-time warning when a message carries #[UniqueJob] or #[RateLimited].
     * DbalQueue cannot enforce these attributes (in-process / SyncQueue-only).
     * Deduplicated per job class per process instance to prevent log spam.
     */
    private function warnIfAttributeNotEnforced(object $message): void
    {
        $className = $message::class;

        // Dedupe: log at most once per job class per process instance.
        if (isset($this->warnedJobClasses[$className])) {
            return;
        }

        $ref = new \ReflectionClass($message);
        $hasUniqueJob = $ref->getAttributes(Attribute\UniqueJob::class) !== [];
        $hasRateLimited = $ref->getAttributes(Attribute\RateLimited::class) !== [];

        if (!$hasUniqueJob && !$hasRateLimited) {
            return;
        }

        $this->warnedJobClasses[$className] = true;

        $labels = [];
        if ($hasUniqueJob) {
            $labels[] = '#[UniqueJob]';
        }
        if ($hasRateLimited) {
            $labels[] = '#[RateLimited]';
        }

        $this->logger->warning(
            sprintf(
                '%s carries %s but DbalQueue does NOT enforce %s (in-process / SyncQueue-only). '
                . 'The job has been pushed to the transport without deduplication or rate-limiting. '
                . 'Cross-process enforcement requires a distributed store and is unimplemented.',
                $className,
                implode(' and ', $labels),
                count($labels) === 1 ? 'this attribute' : 'these attributes',
            ),
            ['job_class' => $className],
        );
    }
}
