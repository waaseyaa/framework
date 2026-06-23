<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Fixtures\AttributeFirstEntities;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Fixture used by EntityTypeBuilderInternalFieldTest to verify that fields
 * marked `settings['internal'] => true` and credential-named fields
 * (`password`, `password_hash`) are dropped from the GraphQL output schema.
 */
#[ContentEntityType(id: 'secret_user', label: 'Secret User')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid')]
final class UserSecretSchemaFixture extends ContentEntityBase
{
    #[Field]
    public ?int $id = null;

    #[Field]
    public string $uuid = '';

    /** Ordinary field — must appear in GraphQL output. */
    #[Field]
    public string $display_name = '';

    /** Credential-named field — dropped by ALWAYS_INTERNAL_FIELDS list. */
    #[Field]
    public string $password = '';

    /** Credential-named field — dropped by ALWAYS_INTERNAL_FIELDS list. */
    #[Field]
    public string $password_hash = '';

    /** Marked internal via settings — dropped by settings['internal'] => true check. */
    #[Field(settings: ['internal' => true])]
    public string $two_factor_secret = '';
}
