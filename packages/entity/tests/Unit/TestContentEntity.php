<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Concrete content entity subclass for testing ContentEntityBase.
 */
#[ContentEntityType(id: 'test_content')]
class TestContentEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $title = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $body = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $field = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $string = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $int = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $float = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $bool = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $array = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $object = null;

    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $null = null;
}
