<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Worker;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Queue\Envelope\NoAuthorityQueueRuntime;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\Occurrence\NoOccurrenceRuntime;
use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Queue\Occurrence\OccurrenceRunResult;
use Waaseyaa\Queue\Occurrence\OccurrenceRuntimeInterface;
use Waaseyaa\Queue\PersistentQueueBoundaryConfig;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Long-running worker that processes jobs from a queue transport.
 * @api
 */
final class Worker
{
    /** @var list<HandlerInterface> */
    private array $handlers;

    private bool $shouldQuit = false;

    private readonly LoggerInterface $logger;

    private readonly QueueAuthorityRuntimeInterface $authorityRuntime;
    private readonly OccurrenceRuntimeInterface $occurrenceRuntime;

    private bool $legacyPayloadDiagnosticEmitted = false;
    private readonly PersistentQueueBoundaryConfig $boundaryConfig;
    private readonly RuntimeEpochInterface $runtimeEpoch;

    /**
     * @param list<HandlerInterface> $handlers
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        array $handlers,
        private readonly SignedQueuePayload $payloadSigner,
        ?LoggerInterface $logger = null,
        ?QueueAuthorityRuntimeInterface $authorityRuntime = null,
        ?PersistentQueueBoundaryConfig $boundaryConfig = null,
        ?OccurrenceRuntimeInterface $occurrenceRuntime = null,
        ?RuntimeEpochInterface $runtimeEpoch = null,
    ) {
        $this->handlers = $handlers;
        $this->logger = $logger ?? new NullLogger();
        $this->authorityRuntime = $authorityRuntime ?? new NoAuthorityQueueRuntime();
        $this->occurrenceRuntime = $occurrenceRuntime ?? new NoOccurrenceRuntime();
        $this->boundaryConfig = $boundaryConfig ?? PersistentQueueBoundaryConfig::dormant();
        $this->runtimeEpoch = $runtimeEpoch ?? new StableRuntimeEpoch();
        if ($this->boundaryConfig->requireAuthorityEnvelope && $this->authorityRuntime instanceof NoAuthorityQueueRuntime) {
            throw new \InvalidArgumentException('Activated queue workers require an authority-restoring runtime.');
        }
    }

    /**
     * Register an additional handler. Handlers added first take priority.
     */
    public function addHandler(HandlerInterface $handler): void
    {
        array_unshift($this->handlers, $handler);
    }

    /**
     * Run the worker loop, processing jobs until a stop condition is met.
     *
     * @return int Number of jobs processed
     */
    public function run(string $queue, WorkerOptions $options): int
    {
        $this->listenForSignals();

        $startTime = time();
        $processed = 0;
        // Compare growth since this run began — not process RSS. PHPUnit (and other hosts)
        // may already exceed memoryLimit megabytes before run(); absolute checks would exit
        // immediately with zero jobs processed (see QueueIntegrationTest under full suite).
        $memoryBaselineBytes = memory_get_usage(true);

        while ($this->shouldContinue($options, $processed, $startTime, $memoryBaselineBytes)) {
            if ($this->runtimeEpoch->hasChanged()) {
                $this->logEpochDrain();
                break;
            }
            $raw = $this->transport->pop($queue);

            if ($raw === null) {
                sleep($options->sleep);
                continue;
            }

            $this->processJob($raw, $queue, $options);
            $processed++;
            if ($this->runtimeEpoch->hasChanged()) {
                $this->logEpochDrain();
                break;
            }
        }

        return $processed;
    }

    /**
     * Process a single job from the queue (non-looping).
     *
     * Useful for testing and single-job processing.
     *
     * @return bool Whether a job was available and processed
     */
    public function runNextJob(string $queue, WorkerOptions $options): bool
    {
        if ($this->runtimeEpoch->hasChanged()) {
            $this->logEpochDrain();

            return false;
        }
        $raw = $this->transport->pop($queue);
        if ($raw === null) {
            return false;
        }

        $this->processJob($raw, $queue, $options);
        if ($this->runtimeEpoch->hasChanged()) {
            $this->logEpochDrain();
        }

        return true;
    }

    private function logEpochDrain(): void
    {
        $this->logger->notice('queue.worker_runtime_epoch_changed', [
            'epoch_fingerprint' => $this->runtimeEpoch->fingerprint(),
            'action' => 'drain',
        ]);
    }

    /**
     * @param array{id: int|string, payload: string, attempts: int} $raw
     */
    private function processJob(array $raw, string $queue, WorkerOptions $options): void
    {
        try {
            $serialized = $this->payloadSigner->open($raw['payload']);
            $decoded = @unserialize($serialized);
        } catch (\Throwable $e) {
            $this->failedJobRepository->record($queue, $raw['payload'], $e);
            $this->transport->reject($raw['id']);

            return;
        }

        if ($decoded === false || !is_object($decoded)) {
            $this->failedJobRepository->record(
                $queue,
                $raw['payload'],
                new \RuntimeException('Failed to unserialize job payload'),
            );
            $this->transport->reject($raw['id']);

            return;
        }

        $envelope = $decoded instanceof QueueEnvelopeV1 ? $decoded : null;
        if ($envelope !== null) {
            try {
                $message = @unserialize($envelope->serializedMessage);
            } catch (\Throwable $e) {
                $this->failedJobRepository->record($queue, $raw['payload'], $e);
                $this->transport->reject($raw['id']);

                return;
            }
            if ($message === false || !is_object($message)) {
                $this->failedJobRepository->record($queue, $raw['payload'], new \RuntimeException('Failed to unserialize enveloped job payload'));
                $this->transport->reject($raw['id']);

                return;
            }
        } else {
            if ($this->boundaryConfig->requireAuthorityEnvelope) {
                $this->failedJobRepository->record(
                    $queue,
                    $raw['payload'],
                    new InvalidPersistentPayload('Activated queue workers reject legacy payloads without a versioned authority envelope.'),
                );
                $this->transport->reject($raw['id']);

                return;
            }
            // Dormant compatibility path. It installs no authority; activation
            // preflight requires these payloads to be drained or requeued.
            $message = $decoded;
            if (!$this->legacyPayloadDiagnosticEmitted) {
                $this->legacyPayloadDiagnosticEmitted = true;
                $this->logger->notice('entity.deprecation', [
                    'boundary' => 'queue',
                    'reason' => 'legacy_payload_without_queue_envelope',
                ]);
            }
        }

        // Crash-recovery safety net (queue M1). A job whose recorded attempts have
        // already reached its max-tries budget — e.g. it was repeatedly claimed
        // then abandoned by crashed workers, each transport reclaim bumping
        // `attempts` — is recorded failed instead of run again. This is what stops
        // an always-crashing job from being reclaimed forever; a healthy job still
        // gets its full quota because `attempts` counts only prior failed/abandoned
        // tries (the in-flight try is added by handleFailure on throw).
        $maxTries = $message instanceof Job ? $message->tries : $options->maxTries;
        if ($maxTries > 0 && $raw['attempts'] >= $maxTries) {
            $exhausted = new \RuntimeException(sprintf(
                'Job exceeded its max attempts (%d) without completing — '
                . 'likely repeated worker crashes / lease expiries (attempts=%d).',
                $maxTries,
                $raw['attempts'],
            ));
            if (
                $envelope?->occurrence !== null
                && !$this->deadLetterOccurrence($envelope->occurrence, $exhausted::class)
            ) {
                $this->transport->defer($raw['id'], 1);

                return;
            }
            $this->failedJobRepository->record(
                $queue,
                $raw['payload'],
                $exhausted,
            );
            $this->transport->reject($raw['id']);

            if ($message instanceof Job) {
                $this->invokeFailureHook($message, new \RuntimeException(
                    'Job exceeded max attempts after lease expiry/abandonment.',
                ));
            }

            return;
        }

        try {
            if ($envelope !== null) {
                if ($envelope->occurrence !== null) {
                    if (!$message instanceof OccurrenceAwareMessageInterface) {
                        throw new InvalidPersistentPayload('Queued scheduler occurrences require an occurrence-aware message.');
                    }
                    $occurrenceResult = $this->authorityRuntime->run(
                        $envelope,
                        fn(): OccurrenceRunResult => $this->occurrenceRuntime->run(
                            $envelope->occurrence,
                            static fn($context) => $message->handleOccurrence($context),
                        ),
                    );
                    if ($occurrenceResult === OccurrenceRunResult::Contended) {
                        $this->transport->defer($raw['id'], 1);

                        return;
                    }
                    if ($occurrenceResult === OccurrenceRunResult::Duplicate) {
                        $this->transport->ack($raw['id']);

                        return;
                    }
                } else {
                    $this->authorityRuntime->run($envelope, function () use ($message): null {
                        $this->handleMessage($message);

                        return null;
                    });
                }
            } else {
                $this->handleMessage($message);
            }

            // Check if a Job released itself back to the queue
            if ($message instanceof Job && $message->isReleased()) {
                $delay = $message->getReleaseDelay();
                $this->transport->release($raw['id'], $delay);

                return;
            }

            $this->transport->ack($raw['id']);
        } catch (\Throwable $e) {
            $this->handleFailure($raw, $queue, $message, $e, $options, $envelope?->occurrence);
        }
    }

    private function handleMessage(object $message): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($message)) {
                $handler->handle($message);

                return;
            }
        }
    }

    private function handleFailure(
        array $raw,
        string $queue,
        object $message,
        \Throwable $e,
        WorkerOptions $options,
        ?QueueOccurrenceV1 $occurrence,
    ): void {
        $maxTries = $message instanceof Job ? $message->tries : $options->maxTries;
        $currentAttempts = $raw['attempts'] + 1; // +1 for the attempt we just made

        if ($currentAttempts < $maxTries) {
            $delay = $this->calculateBackoff($message, $currentAttempts);
            $this->transport->release($raw['id'], $delay);
        } else {
            if ($occurrence !== null && !$this->deadLetterOccurrence($occurrence, $e::class)) {
                $this->transport->defer($raw['id'], 1);

                return;
            }
            $this->failedJobRepository->record($queue, $raw['payload'], $e);
            $this->transport->reject($raw['id']);

            if ($message instanceof Job) {
                $this->invokeFailureHook($message, $e);
            }
        }
    }

    private function deadLetterOccurrence(QueueOccurrenceV1 $occurrence, string $failureClass): bool
    {
        try {
            return $this->occurrenceRuntime->deadLetter($occurrence, $failureClass);
        } catch (\Throwable $error) {
            $this->logger->error('queue.occurrence_dead_letter_failed', [
                'task' => $occurrence->taskName,
                'exception' => $error,
            ]);

            return false;
        }
    }

    private function invokeFailureHook(Job $job, \Throwable $original): void
    {
        try {
            $job->failed($original);
        } catch (\Throwable $exception) {
            $this->logger->error('queue.failure_hook_failed', [
                'job' => $job::class,
                'original_exception' => $original,
                'exception' => $exception,
            ]);
        }
    }

    private function calculateBackoff(object $message, int $attempts): int
    {
        $baseDelay = $message instanceof Job ? $message->retryAfter : 5;
        if ($baseDelay <= 0) {
            return 0;
        }

        return min($baseDelay * (2 ** ($attempts - 1)), 3600);
    }

    private function shouldContinue(
        WorkerOptions $options,
        int $processed,
        int $startTime,
        int $memoryBaselineBytes,
    ): bool {
        if ($this->shouldQuit) {
            return false;
        }

        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
            return false;
        }

        if ($options->maxTime > 0 && (time() - $startTime) >= $options->maxTime) {
            return false;
        }

        if ($options->memoryLimit > 0) {
            $limitBytes = $options->memoryLimit * 1024 * 1024;
            if ((memory_get_usage(true) - $memoryBaselineBytes) >= $limitBytes) {
                return false;
            }
        }

        return true;
    }

    private function listenForSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(\SIGTERM, fn() => $this->shouldQuit = true);
        pcntl_signal(\SIGINT, fn() => $this->shouldQuit = true);
    }

    /**
     * Request graceful shutdown. The worker will finish its current job and exit.
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }
}
