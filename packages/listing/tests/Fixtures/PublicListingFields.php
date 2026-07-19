<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\FieldReadLevel;

/** Explicit public-value vocabulary shared by listing surface fixtures. */
trait PublicListingFields
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $status = false;

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Public)]
    public ?int $weight = null;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $category = null;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $starts_at = null;
}
