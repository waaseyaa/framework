<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract\Fixtures;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Listing\Tests\Fixtures\PublicListingFields;

/**
 * Test article entity for the resolver contract suite.
 *
 * Defines core fields used in the resolver tests: id, title, status, weight.
 * Bound to entity type id `article`. The constructor mirrors
 * {@see ContentEntityBase::__construct()} verbatim so the storage hydration
 * pipeline (which passes `values:` + `entityTypeId:` + `entityKeys:` +
 * `fieldDefinitions:` named arguments) instantiates this subclass correctly.
 */
final class ArticleEntity extends ContentEntityBase
{
    use PublicListingFields;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = ['id' => 'id', 'label' => 'title'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'article',
            $entityKeys !== [] ? $entityKeys : ['id' => 'id', 'label' => 'title'],
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'article';
    }
}
