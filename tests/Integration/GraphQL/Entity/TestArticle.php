<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TestArticle extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
