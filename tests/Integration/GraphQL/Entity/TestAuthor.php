<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TestAuthor extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'author',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
