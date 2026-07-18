<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Support;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldStorage;

/** Reviewed field classifications shared by workflow integration fixtures. */
trait WorkflowSubjectFields
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $id = '';
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $uuid = '';
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title = '';
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $type = '';
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $revision_id = 0;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $published_revision_id = 0;
    #[Field(type: 'boolean', settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)] public bool $status = false;
    #[Field(type: 'string', settings: ['authorizationInput' => true], stored: FieldStorage::Data, read: FieldReadLevel::Protected)] public ?string $workflow_state = null;
}
