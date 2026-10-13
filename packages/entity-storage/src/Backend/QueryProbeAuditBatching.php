<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Database\DBALDatabase;

/** Shared persistence semantics for the audit-provider and core fallback. @internal */
trait QueryProbeAuditBatching
{
    private ?DBALDatabase $batchDatabase = null;

    public function reserveMany(array $attempts): array
    {
        if ($attempts === []) {
            throw new \InvalidArgumentException('Query audit batches require a non-empty ordered list.');
        }
        foreach ($attempts as $attempt) {
            if ($attempt->operation !== FieldStorageGatewayOperation::SupportsQuery) {
                throw new \InvalidArgumentException('Only query-capability probes may use batched gateway auditing.');
            }
        }
        $transaction = $this->beginProbeBatch();
        $receipts = [];
        try {
            foreach ($attempts as $attempt) {
                $receipts[] = $this->reserve($attempt);
            }
            $transaction->commit();
            return $receipts;
        } catch (\Throwable $error) {
            foreach ($receipts as $receipt) {
                unset($this->receiptIds[$receipt]);
            }
            $this->rollBackProbeBatch($transaction, $error);
            throw $error;
        }
    }

    public function finalizeMany(array $outcomes): void
    {
        if ($outcomes === []) {
            throw new \InvalidArgumentException('Query audit outcomes require a non-empty ordered list.');
        }
        $ids = new \SplObjectStorage();
        foreach ($outcomes as $outcome) {
            $receipt = $outcome['receipt'];
            $failure = $outcome['failure'];
            if ($receipt->attempt->operation !== FieldStorageGatewayOperation::SupportsQuery
                || !isset($this->receiptIds[$receipt]) || $ids->offsetExists($receipt)
                || ($failure !== null && $failure->attempt !== $receipt->attempt)) {
                throw new \LogicException('Query audit outcomes require distinct, live, matching probe receipts.');
            }
            $ids[$receipt] = $this->receiptIds[$receipt];
        }
        $transaction = $this->beginProbeBatch();
        try {
            foreach ($outcomes as $outcome) {
                if ($outcome['failure'] === null) {
                    $this->succeed($outcome['receipt']);
                } else {
                    $this->fail($outcome['receipt'], $outcome['failure']);
                }
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            // Receipts are usable again only after rollback is confirmed.
            foreach ($ids as $receipt) {
                unset($this->receiptIds[$receipt]);
            }
            $this->rollBackProbeBatch($transaction, $error);
            foreach ($ids as $receipt) {
                $this->receiptIds[$receipt] = $ids[$receipt];
            }
            throw $error;
        }
    }

    private function rollBackProbeBatch(\Waaseyaa\Database\TransactionInterface $transaction, \Throwable $original): void
    {
        try {
            $transaction->rollBack();
        } catch (\Throwable) {
            throw new \RuntimeException('Query audit persistence failed and rollback could not be confirmed.', previous: $original);
        }
    }

    private function beginProbeBatch(): \Waaseyaa\Database\TransactionInterface
    {
        // A savepoint cannot establish pre-invocation durability. Batch only
        // when the actual connection can commit independently of caller work.
        if ($this->batchDatabase === null || $this->batchDatabase->getConnection()->isTransactionActive()) {
            throw new \LogicException('Query audit batching requires a DBAL database outside a caller transaction.');
        }
        return $this->database->transaction('field-query-probes');
    }
}
