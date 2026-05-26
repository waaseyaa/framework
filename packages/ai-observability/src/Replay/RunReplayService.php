<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Replay;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\AI\Observability\Trace;
use Waaseyaa\AI\Pipeline\PipelineDispatcher;
use Waaseyaa\Api\AiObservability\Runs\RunReplayResult;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Replays an existing trace by re-dispatching its pipeline asynchronously.
 *
 * Looks up the original trace to resolve the pipeline label (which equals
 * the pipeline config entity ID), creates a new queued Trace entity, and
 * dispatches the pipeline via PipelineDispatcher (fire-and-forget).
 */
final class RunReplayService implements RunReplayServiceInterface
{
    private readonly EntityRepositoryInterface $traces;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly PipelineDispatcher $pipelineDispatcher,
    ) {
        $this->traces = $this->entityTypeManager->getRepository('trace');
    }

    public function replay(string $traceUuid): RunReplayResult
    {
        // Find the original trace
        $matches = $this->traces->findBy(['uuid' => $traceUuid]);
        if ($matches === []) {
            throw new \RuntimeException(\sprintf('Trace "%s" not found.', $traceUuid));
        }

        $originalTrace = reset($matches);
        /** @var Trace $originalTrace */
        $pipelineId = (string) ($originalTrace->get('label') ?? '');
        if ($pipelineId === '') {
            throw new \RuntimeException(\sprintf('Trace "%s" has no pipeline label; cannot replay.', $traceUuid));
        }

        // Look up the pipeline config entity
        $pipelineRepo = $this->entityTypeManager->getRepository('pipeline');
        $pipelines = $pipelineRepo->findBy(['id' => $pipelineId]);
        if ($pipelines === []) {
            throw new \RuntimeException(\sprintf('Pipeline "%s" not found; cannot replay trace "%s".', $pipelineId, $traceUuid));
        }

        $pipeline = reset($pipelines);
        /** @var \Waaseyaa\AI\Pipeline\Pipeline $pipeline */

        // Create a new trace entity for the replayed run
        $newUuid = Uuid::v4()->toRfc4122();
        $startedAt = new \DateTimeImmutable();

        $newTrace = new Trace([
            'uuid' => $newUuid,
            'label' => $pipelineId,
            'status' => 'queued',
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
        ]);
        $newTrace->enforceIsNew();
        $this->traces->save($newTrace);

        // Dispatch pipeline asynchronously (fire-and-forget)
        $this->pipelineDispatcher->dispatch($pipeline, ['replay_of' => $traceUuid]);

        return new RunReplayResult(
            newRunUuid: $newUuid,
            status: 'queued',
            startedAt: $startedAt->format('Y-m-d H:i:s'),
        );
    }
}
