<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;

/**
 * DB-backed KeyMaterialProvider that delegates to SigningKeyRepository.
 *
 * Replaces WP01's InMemoryKeyMaterialProvider binding in OidcServiceProvider.
 *
 * @api
 */
final class RealKeyMaterialProvider implements KeyMaterialProviderInterface
{
    public function __construct(private readonly SigningKeyRepository $repository) {}

    public function currentKey(): SigningKey
    {
        return $this->repository->currentKey();
    }

    public function allActive(): array
    {
        return $this->repository->allActive();
    }
}
