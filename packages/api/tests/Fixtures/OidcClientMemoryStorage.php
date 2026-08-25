<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * In-memory storage that instantiates {@see OidcClient} instead of TestEntity.
 *
 * @internal
 */
final class OidcClientMemoryStorage extends InMemoryEntityStorage
{
    public function __construct()
    {
        parent::__construct('oidc_client');
    }

    public function create(array $values = []): EntityInterface
    {
        return new OidcClient($values);
    }
}
