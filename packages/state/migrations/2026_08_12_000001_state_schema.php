<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if ($schema->hasTable('state')) {
            return;
        }
        $schema->getConnection()->executeStatement(
            'CREATE TABLE state (name VARCHAR(255) PRIMARY KEY NOT NULL, value TEXT)',
        );
    }

    public function down(SchemaBuilder $schema): void {}
};
