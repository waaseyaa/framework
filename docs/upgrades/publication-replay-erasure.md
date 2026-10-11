# Publication replay erasure

The supported `IdempotencyStore::eraseContentResponses($uuid)` operation removes
all retained publication snapshots for a canonical entity UUID. It leaves only
permanent opaque retry-key markers, which refuse old requests with
`ContentNotFoundException` without performing mutations or TTL cleanup.

Authorize erasure in the consuming application, capture the canonical UUID
before deletion and use the same managed database for both operations:

```php
if (!$repository->sharesTransactionWith($database)) {
    throw new LogicException('Deletion and replay erasure must share a transaction.');
}
$database->transactional(function () use ($repository, $idempotency, $entity): void {
    $uuid = $entity->uuid();
    $repository->delete($entity);
    $idempotency->eraseContentResponses($uuid);
});
```

The example uses `DBALDatabase::transactional()`, which preserves committed
completion-failure semantics. Do not treat `TransactionCompletionException`
after commit as a rolled-back deletion. On pre-commit failure, retry the whole
coordinated operation. Fence publication at this deletion boundary and retain
its existing mutation-token and authorization checks. A background POST_DELETE
listener alone cannot supply atomic deletion and erasure.

This covers all response records still present, including expired unswept and
legacy snapshots bearing that UUID. ContentPublisher always supplies opaque
hashed keys. Direct IdempotencyStore callers that used content-bearing raw keys
must remediate those keys separately before claiming complete content erasure;
this operation clears responses and fingerprints but retains the key verbatim.
Already-swept keys cannot be recovered. Reject historical retries lacking
retained protection when that guarantee is required. Fresh keys remain ordinary
requests. UUIDs must uniquely identify an aggregate across the store and must
never be reassigned. No new schema or migration is required.

See [the contract](../specs/content-publishing.md#targeted-publication-replay-erasure)
and [change record](../change-records/FW-PUBLICATION-REPLAY-ERASURE-01.md).
This local candidate is not a published package cohort; consumer qualification
and publication remain separate. The alpha hold remains in force.
