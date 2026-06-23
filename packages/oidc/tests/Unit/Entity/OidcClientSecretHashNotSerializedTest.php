<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * `oidc_client.client_secret_hash` must never reach the API output — its `#[Field]`
 * docblock says "Never exposed through the API", and `OidcClientAccessPolicy`
 * forbids `view` of it. But that field-access filter only runs when the serializer
 * is given an access handler + account; a code path that serializes an
 * `oidc_client` without one would skip the policy layer.
 *
 * This pins the *second* layer: the field is marked `internal: true`, so
 * `ResourceSerializer::filterInternalFields()` and the ai-tools `EntityReadTool`
 * internal-drop strip it unconditionally — the same double-layer protection
 * (policy + internal-drop) the User credential fields enjoy. This is the only
 * non-User credential field on a live entity surface (C-11 cross-entity-type
 * deny-list completeness sweep); the policy was its sole protection before this.
 */
#[CoversNothing]
final class OidcClientSecretHashNotSerializedTest extends TestCase
{
    #[Test]
    public function clientSecretHashFieldIsMarkedInternal(): void
    {
        $property = new \ReflectionProperty(OidcClient::class, 'client_secret_hash');
        $attributes = $property->getAttributes(Field::class);

        self::assertNotEmpty($attributes, 'client_secret_hash must be a declared #[Field].');

        $field = $attributes[0]->newInstance();
        self::assertTrue(
            ($field->settings['internal'] ?? null) === true,
            "oidc_client.client_secret_hash must declare settings['internal' => true] so the serializer / agent-tool "
            . 'internal-drop strips it unconditionally — not relying on the access policy alone (C-11 double-layer).',
        );
    }
}
