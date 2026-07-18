<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Fixtures;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Reference consumer entity for the listing-pipeline integration tests
 * and the M-007 cookbook quickstart.
 *
 * Defines four core fields:
 *  - `id`         (integer, primary key)
 *  - `title`      (string)
 *  - `starts_at`  (datetime — ISO-8601 string)
 *  - `category`   (string)
 *
 * The constructor mirrors {@see ContentEntityBase::__construct()} verbatim
 * so the storage hydration pipeline (which passes `values:` +
 * `entityTypeId:` + `entityKeys:` + `fieldDefinitions:` named arguments)
 * instantiates this subclass correctly.
 *
 * @api
 */
final class EventEntity extends ContentEntityBase
{
    use PublicListingFields;

    /**
     * @param array<string, mixed>     $values
     * @param array<string, string>    $entityKeys
     * @param array<string, mixed>     $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = 'event',
        array $entityKeys = [
            'id' => 'id',
            'title' => 'title',
            'starts_at' => 'starts_at',
            'category' => 'category',
        ],
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'event',
            $entityKeys !== [] ? $entityKeys : [
                'id' => 'id',
                'title' => 'title',
                'starts_at' => 'starts_at',
                'category' => 'category',
            ],
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'event';
    }
}
