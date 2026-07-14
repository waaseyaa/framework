<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Waaseyaa\Entity\EntityType;

/**
 * Entity type definition for media_version.
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 */
final class MediaVersionType
{
    public static function create(): EntityType
    {
        return new EntityType(
            id: 'media_version',
            label: 'Media Version',
            class: MediaVersion::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            description: 'Content-addressed blob version record for a media entity.',
        );
    }
}
