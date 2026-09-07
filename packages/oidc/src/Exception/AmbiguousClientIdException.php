<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Exception;

/**
 * Refuses an impossible multi-match `client_id` lookup (#2766).
 *
 * `OidcClientLookup::findByClientId()` is the pre-auth resolution path shared
 * by the authorize, token, refresh, and revoke controls. The database unique
 * key on `oidc_client.client_id` (see the `2026_09_06_000009_...` migration)
 * should make more than one matching row structurally impossible. This
 * exception is the defense-in-depth backstop for that invariant: if it is
 * ever violated anyway — for example a database that has not yet run the
 * uniqueness migration, or a backend that cannot enforce the constraint —
 * the lookup fails closed instead of silently choosing `$ids[0]` and
 * authenticating against an arbitrary row.
 *
 * `OidcClient` declares no `#[StorageUniqueKey]` — the physical constraint is
 * owned entirely by the `2026_09_06_000009_oidc_client_id_unique_key`
 * migration, not by the declarative schema-sync mechanism. The recovery
 * instruction in the message below therefore names `bin/waaseyaa migrate`,
 * never `schema:sync`, which would not create this index.
 *
 * @api
 */
final class AmbiguousClientIdException extends \RuntimeException
{
    public readonly string $errorCode;

    /** @param non-empty-list<string> $matchingIds */
    public function __construct(public readonly string $clientId, public readonly array $matchingIds)
    {
        $this->errorCode = 'oidc_client_id_ambiguous';
        parent::__construct(\sprintf(
            '[oidc_client_id_ambiguous] Refusing to resolve client_id "%s": %d rows match (ids: %s). '
            . 'client_id must be a unique registry identity; reconcile the underlying duplicate rows, '
            . 'then run `bin/waaseyaa migrate` to materialize the uniqueness constraint.',
            $clientId,
            \count($matchingIds),
            \implode(', ', $matchingIds),
        ));
    }
}
