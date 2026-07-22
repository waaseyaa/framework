<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\ForeignKeySchemaInterface;

/** Installs the term-to-vocabulary restrictive foreign key on migrated tables. */
final class VocabularyReferenceConstraint
{
    public const string NAME = 'taxonomy_term_vocabulary_fk';

    public function __construct(private readonly DatabaseInterface $database) {}

    public function ensure(): void
    {
        $schema = $this->database->schema();
        if (!$schema instanceof ForeignKeySchemaInterface
            || !$schema->tableExists('taxonomy_term')
            || !$schema->tableExists('taxonomy_vocabulary')
        ) {
            return;
        }

        $schema->addForeignKey(
            'taxonomy_term',
            self::NAME,
            ['vid'],
            'taxonomy_vocabulary',
            ['vid'],
            ['onDelete' => 'RESTRICT', 'onUpdate' => 'CASCADE'],
        );
    }
}
