<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\FieldReadLevel;

/** Explicit public-value vocabulary shared by API surface fixtures. */
trait ApiPublicContentFields
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'text', required: false, read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $secret = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $category = '';

    #[Field(type: 'float', required: false, read: FieldReadLevel::Public)]
    public int|float|null $weight = null;

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $status = false;

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $promote = false;

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Public)]
    public ?int $created = null;

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Public)]
    public ?int $changed = null;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $name = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $summary = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $hero = '';

    /** @var string|list<string> */
    #[Field(type: 'string', required: false, read: FieldReadLevel::Public)]
    public string|array $related = '';

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $flagged = false;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $secret_note = '';

    /** @var list<string> */
    #[Field(required: false, settings: ['subtype' => 'string_list'], read: FieldReadLevel::Public)]
    public array $tags = [];

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $phase = '';

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Public)]
    public int|string|null $published_at = null;
}
