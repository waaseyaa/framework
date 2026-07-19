<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\Envelope\QueueEnvelopeFactoryInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\Security\SignedQueuePayload;
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
final class DbalQueue implements QueueInterface, PersistentPayloadReplayInterface
{
    private readonly LoggerInterface $logger;

    private readonly ?QueueEnvelopeFactoryInterface $envelopeFactory;

    private readonly QueuePayloadDeprecationDiagnostic $payloadDiagnostic;
    private readonly QueuePayloadDeprecationDiagnostic $activationDiagnostic;
    private readonly PersistentQueueBoundaryConfig $boundaryConfig;

    private bool $missingAuthorityDiagnosticEmitted = false;

    /**
     * Per-process set of job class names already warned about unenforced attributes.
     * Prevents log spam when the same job class is dispatched multiple times.
     *
     * @var array<string, true>
     */
    private array $warnedJobClasses = [];

    /**
     * @param TransportInterface   $transport    Persistent transport backend.
     * @param SignedQueuePayload   $payloadSigner Application-derived payload authenticator.
     * @param string               $defaultQueue Queue name used when no #[OnQueue] attribute is present.
     * @param LoggerInterface|null $logger       Optional logger for unenforced-attribute warnings.
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly SignedQueuePayload $payloadSigner,
        private readonly string $defaultQueue = 'default',
        ?LoggerInterface $logger = null,
        ?QueueEnvelopeFactoryInterface $envelopeFactory = null,
        ?QueuePayloadDeprecationDiagnostic $payloadDiagnostic = null,
        ?PersistentQueueBoundaryConfig $boundaryConfig = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->envelopeFactory = $envelopeFactory;
        $this->boundaryConfig = $boundaryConfig ?? PersistentQueueBoundaryConfig::dormant();
        $defaultDiagnostic = new QueuePayloadDeprecationDiagnostic(
            function (string $code, array $context): void {
                $this->logger->notice($code, $context);
            },
            $this->boundaryConfig,
        );
        $this->payloadDiagnostic = $payloadDiagnostic ?? $defaultDiagnostic;
        $this->activationDiagnostic = $payloadDiagnostic === null
            ? $defaultDiagnostic
            : new QueuePayloadDeprecationDiagnostic(static function (): void {}, $this->boundaryConfig);
    }

    public function dispatch(object $message): void
    {
        if ($message instanceof QueueEnvelopeV1) {
            throw new \InvalidArgumentException(
                'Queue authority envelopes can only be created by the configured envelope factory.',
            );
        }

        $this->warnIfAttributeNotEnforced($message);
        $this->payloadDiagnostic->inspect($message);
        if ($this->activationDiagnostic !== $this->payloadDiagnostic) {
            $this->activationDiagnostic->inspect($message);
        }

        if ($this->boundaryConfig->requireAuthorityEnvelope && $this->envelopeFactory === null) {
            throw new InvalidPersistentPayload('Activated persistent queue dispatch requires a reviewed authority envelope factory.');
        }

        $queue = $this->resolveQueue($message);
        $delay = $this->resolveDelay($message);
        $serializedMessage = serialize($message);
        if ($this->envelopeFactory !== null) {
            $serializedMessage = serialize($this->envelopeFactory->wrap($message, $serializedMessage));
        } elseif (!$this->missingAuthorityDiagnosticEmitted) {
            $this->missingAuthorityDiagnosticEmitted = true;
            $this->logger->notice('entity.deprecation', [
                'boundary' => 'queue.dispatch',
                'reason' => 'persistent_dispatch_without_authority_declaration',
            ]);
        }
        $payload = $this->payloadSigner->seal($serializedMessage);

        $this->transport->push($queue, $payload, $delay);
    }

    public function replaySignedPayload(string $queue, string $signedPayload): void
    {
        // Authenticate before storage so an operator retry cannot enqueue an
        // invalid failed-row payload. The exact bytes, including authority and
        // correlation metadata, are then preserved for the worker.
        try {
            $opened = $this->payloadSigner->open($signedPayload);
        } catch (\Throwable $error) {
            throw new InvalidPersistentPayload('Persistent queue payload authentication failed.', previous: $error);
        }
        if ($this->boundaryConfig->requireAuthorityEnvelope) {
            try {
                $decoded = unserialize($opened, ['allowed_classes' => [QueueEnvelopeV1::class]]);
            } catch (\Throwable $error) {
                throw new InvalidPersistentPayload('Activated persistent queue retry requires a QueueEnvelopeV1 payload.', previous: $error);
            }
            if (!$decoded instanceof QueueEnvelopeV1) {
                throw new InvalidPersistentPayload('Activated persistent queue retry requires a QueueEnvelopeV1 payload.');
            }
        }
        $this->transport->push($queue, $signedPayload);
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
