<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('embedding_generations', static function (TableBuilder $table): void {
            $table->string('entity_type', 128);
            $table->string('entity_id', 255);
            $table->string('token', 32);
            $table->primary(['entity_type', 'entity_id']);
        });
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only. Retain tombstones so old in-flight calls stay fenced.
    }
};
