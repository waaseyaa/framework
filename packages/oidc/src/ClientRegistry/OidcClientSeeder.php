<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\ClientRegistry;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * Seeds OidcClient entities from config.
 *
 * Reads the `oidc.clients` config map and upserts each entry into storage by
 * `client_id`. Non-destructive: entries removed from config are not deleted
 * from the database, and admin-created clients (not present in config) are
 * untouched.
 *
 * Expected config shape:
 *
 *     'oidc' => [
 *         'clients' => [
 *             'minoo-web' => [
 *                 'name' => 'Minoo',
 *                 'redirect_uris' => ['https://minoo.test/callback'],
 *                 'scopes' => ['openid', 'profile'],           // optional
 *                 'grant_types' => ['authorization_code'],     // optional
 *                 'is_confidential' => false,                  // optional
 *                 'client_secret_hash' => null,                // optional
 *             ],
 *         ],
 *     ]
 */
final class OidcClientSeeder
{
    private readonly OidcClientLookup $lookup;

    public function __construct(
        // C-22 WP2/WP3: create/save/query all go through the canonical repository.
        private readonly EntityRepositoryInterface $repository,
    ) {
        // Share the fail-closed multi-match refusal (#2766) instead of
        // duplicating the query here — a legacy pre-migration database with
        // existing client_id duplicates must not seed a config-defined
        // update onto an arbitrarily chosen row.
        $this->lookup = new OidcClientLookup($repository);
    }

    /**
     * @param array<array-key, array<string, mixed>> $clients client_id => config
     */
    public function seed(array $clients): void
    {
        foreach ($clients as $clientId => $config) {
            if (!is_string($clientId) || $clientId === '') {
                throw new \InvalidArgumentException(
                    'OIDC client config key must be a non-empty string (the client_id).',
                );
            }

            $this->seedOne($clientId, $config);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function seedOne(string $clientId, array $config): void
    {
        $this->validate($clientId, $config);

        $values = [
            'client_id' => $clientId,
            'name' => (string) $config['name'],
            'redirect_uris' => array_values($config['redirect_uris']),
        ];

        foreach (['scopes', 'grant_types'] as $arrayField) {
            if (isset($config[$arrayField]) && is_array($config[$arrayField])) {
                $values[$arrayField] = array_values($config[$arrayField]);
            }
        }

        if (array_key_exists('is_confidential', $config)) {
            $values['is_confidential'] = (bool) $config['is_confidential'];
        }

        if (array_key_exists('client_secret_hash', $config)) {
            $hash = $config['client_secret_hash'];
            $values['client_secret_hash'] = is_string($hash) && $hash !== '' ? $hash : null;
        }

        $existing = $this->findByClientId($clientId);

        if ($existing !== null) {
            $this->applyValues($existing, $values);
            $this->repository->save($existing);
            return;
        }

        // #2766: client_id is a database-enforced unique registry identity.
        // Two boot processes can both observe findByClientId() === null and
        // both attempt to create the same client_id; the unique key is the
        // race-closing authority. Rather than surface a raw DBAL violation on
        // every concurrent-boot race, catch it and recover by updating the
        // row the concurrent writer just won — the same idempotent-seeding
        // outcome a strictly-sequential boot would have produced.
        $entity = $this->repository->create($values);
        try {
            $this->repository->save($entity);
        } catch (UniqueConstraintViolationException $violation) {
            $winner = $this->findByClientId($clientId);
            if ($winner === null) {
                // The violation was not on client_id (e.g. a colliding uuid);
                // nothing to recover from here.
                throw $violation;
            }
            $this->applyValues($winner, $values);
            $this->repository->save($winner);
        }
    }

    /** @param array<string, mixed> $values */
    private function applyValues(OidcClient $entity, array $values): void
    {
        foreach ($values as $field => $value) {
            $entity->set($field, $value);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(string $clientId, array $config): void
    {
        if (!isset($config['name']) || !is_string($config['name']) || $config['name'] === '') {
            throw new \InvalidArgumentException(
                "OIDC client '$clientId' is missing required 'name'.",
            );
        }

        if (!isset($config['redirect_uris'])) {
            throw new \InvalidArgumentException(
                "OIDC client '$clientId' is missing required 'redirect_uris'.",
            );
        }

        if (!is_array($config['redirect_uris']) || $config['redirect_uris'] === []) {
            throw new \InvalidArgumentException(
                "OIDC client '$clientId' must declare at least one redirect_uri.",
            );
        }
    }

    private function findByClientId(string $clientId): ?OidcClient
    {
        return $this->lookup->findByClientId($clientId);
    }
}
