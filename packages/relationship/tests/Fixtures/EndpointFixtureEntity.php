<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Minimal content entity standing in for a "real" endpoint entity type (e.g.
 * node) whose own {@see \Waaseyaa\Access\AccessPolicyInterface} may hide it
 * from a given account, so relationship endpoint-visibility tests don't need
 * a full node/storage stack. Carries a `published` flag consumed by
 * {@see EndpointFixtureAccessPolicy}.
 *
 * @internal Test double for Relationship package tests.
 */
#[ContentEntityType(id: 'endpoint_entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class EndpointFixtureEntity extends ContentEntityBase
{
    #[Field(settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)]
    public bool $published = false;
}
