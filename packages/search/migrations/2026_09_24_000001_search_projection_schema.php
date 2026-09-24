<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Search\Fts5\Fts5SearchSchema;

/**
 * Owns the FTS5 search projection on the application database
 * (FW-SEARCH-PERSIST-01): `search_index`, `search_metadata` and the
 * `idx_search_meta_*` indexes.
 *
 * Absent objects are created. Objects with the expected definition, including
 * ones the pre-migration runtime code created, are adopted in place with every
 * row. A retired Porter-tokenized `search_index` is rebuilt with its rows. Any
 * other shape is refused with `[SEARCH-DB001]` and the coordinator rolls the
 * transition back.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        Fts5SearchSchema::install($schema->getConnection());
    }
};
