<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Exception;

/**
 * Refuses to materialize the `oidc_client.client_id` uniqueness constraint
 * when existing rows already share a `client_id` value (#2766).
 *
 * `client_id` is the OIDC client registry's stable public identifier —
 * every authorize/token/refresh/revoke path resolves a client by this value
 * before authentication runs. Prior to the migration that throws this
 * exception, the column carried no database constraint, so a race or
 * operator error could seed two rows with the same `client_id`.
 *
 * The framework never guesses which duplicate row is "correct". It refuses
 * schema materialization and reports the exact duplicated values so an
 * operator can manually reconcile (rename, merge, or remove) the conflicting
 * client definitions. No row is deleted or merged automatically.
 *
 * @api
 */
final class DuplicateClientIdException extends \RuntimeException
{
    public readonly string $errorCode;

    /** @param non-empty-list<string> $duplicateClientIds */
    public function __construct(public readonly array $duplicateClientIds)
    {
        $this->errorCode = 'oidc_client_id_duplicates';
        parent::__construct(\sprintf(
            '[oidc_client_id_duplicates] Cannot materialize the oidc_client.client_id unique key: '
            . '%d client_id value(s) are duplicated across existing rows: %s. Resolve the duplicates '
            . '(rename, merge, or remove the conflicting client definitions), then rerun '
            . '`bin/waaseyaa migrate`. No row was deleted or merged automatically.',
            \count($duplicateClientIds),
            \implode(', ', $duplicateClientIds),
        ));
    }
}
