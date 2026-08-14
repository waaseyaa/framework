<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

/**
 * @api
 */
final class RelationshipSchemaManager
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function ensure(): void
    {
        SchemaRequirement::assertAvailable(
            $this->database,
            'relationship',
            [
                'relationship_type', 'from_entity_type', 'from_entity_id',
                'to_entity_type', 'to_entity_id', 'directionality', 'status',
                'weight', 'start_date', 'end_date', 'confidence', 'source_ref', 'notes',
            ],
            'the coordinated entity-schema plan for relationship',
        );
    }
}
