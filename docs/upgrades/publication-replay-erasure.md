# Publication replay erasure

The supported `IdempotencyStore::eraseContentResponses($uuid)` operation removes
all retained publication snapshots for a canonical entity UUID. It leaves only
permanent opaque retry-key markers, which refuse old requests with
`ContentNotFoundException` without performing mutations or TTL cleanup.

Before the first erasure, stop publication ingress and drain in-flight requests.
On the same durable database, call `$idempotency->enableIssuedKeyAdmission()`;
then resume ingress with keys from `$idempotency->issueKey()`. Deliver each key
to the client before its first mutation and require the client to reuse that
exact key on retry. The switch is durable and irreversible. Unknown old-format
keys are refused, including legacy keys already deleted by an older TTL sweep.
Existing retained old-format responses may replay until expiry. Keys issued
more than the configured TTL ago are also refused. Issued keys carry an HMAC
under a random secret stored at cutover, so an already-swept legacy key that
merely resembles the new format cannot pass admission. The issuer is a supported
admission operation; it does not replace publication authorization.

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
Already-swept keys cannot be recovered; the admission cutover rejects them.
The ordinary sweep now removes expired response data but retains a content-free
refusal marker, including for newly created records. Fresh issued keys remain
ordinary new requests. UUIDs must uniquely identify an aggregate across the store and must
never be reassigned. No new schema or migration is required.

See [the contract](../specs/content-publishing.md#targeted-publication-replay-erasure)
and [change record](../change-records/FW-PUBLICATION-REPLAY-ERASURE-01.md).
This local candidate is not a published package cohort; consumer qualification
and publication remain separate. The alpha hold remains in force.
