<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasColumn('audit_checkpoint', 'prune_authorization')) {
            $schema->getConnection()->executeStatement(
                "ALTER TABLE audit_checkpoint ADD COLUMN prune_authorization TEXT NOT NULL DEFAULT ''",
            );
        }
    }

    public function down(SchemaBuilder $schema): void {}
};
