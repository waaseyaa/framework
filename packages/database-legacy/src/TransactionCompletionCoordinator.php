<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Waaseyaa\Database\Exception\TransactionCompletionException;

/** @internal Shared per-connection completion frames for managed transactions. */
final class TransactionCompletionCoordinator
{
    /** @var list<array{id: int, callbacks: list<\Closure(): void>}> */
    private array $frames = [];

    private int $nextFrameId = 1;

    public function begin(\Doctrine\DBAL\Connection $connection): int
    {
        $managedDepth = count($this->frames);
        $actualDepth = $connection->getTransactionNestingLevel();
        if ($actualDepth !== $managedDepth) {
            throw new \LogicException(sprintf(
                'Cannot nest a framework transaction inside an unmanaged Doctrine transaction '
                . '(managed depth %d, connection depth %d). Use DatabaseInterface::transaction() '
                . 'for the outer boundary so completion follows the outermost commit.',
                $managedDepth,
                $actualDepth,
            ));
        }

        $connection->beginTransaction();
        $frameId = $this->nextFrameId++;
        $this->frames[] = ['id' => $frameId, 'callbacks' => []];

        return $frameId;
    }

    public function afterCommit(int $frameId, \Closure $callback): void
    {
        $index = $this->assertTopFrame($frameId);
        $this->frames[$index]['callbacks'][] = $callback;
    }

    public function committed(int $frameId): void
    {
        $index = $this->assertTopFrame($frameId);
        $frame = $this->frames[$index];
        array_pop($this->frames);

        if ($this->frames !== []) {
            $parent = array_key_last($this->frames);
            array_push($this->frames[$parent]['callbacks'], ...$frame['callbacks']);

            return;
        }

        $failures = [];
        foreach ($frame['callbacks'] as $callback) {
            try {
                $callback();
            } catch (TransactionCompletionException $failure) {
                array_push($failures, ...$failure->failures());
            } catch (\Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            throw new TransactionCompletionException($failures);
        }
    }

    public function rolledBack(int $frameId): void
    {
        $this->assertTopFrame($frameId);
        array_pop($this->frames);
    }

    private function assertTopFrame(int $frameId): int
    {
        $index = array_key_last($this->frames);
        if ($index === null || $this->frames[$index]['id'] !== $frameId) {
            throw new \LogicException('Transactions must complete in last-opened, first-closed order.');
        }

        return $index;
    }
}
