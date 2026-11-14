<?php

declare(strict_types=1);
return new class extends \Waaseyaa\Foundation\Migration\Migration {
    public function up(\Waaseyaa\Foundation\Migration\SchemaBuilder $schema): void
    {
        $schema->create('engagement_fixture_policy', static function ($table): void {
            $table->id();
            $table->integer('generation')->default(1);
            $table->integer('root_visible')->default(1);
            $table->integer('alice_blocked')->default(0);
            $table->integer('alice_suspended')->default(0);
            $table->integer('bob_moderator')->default(0);
        });
    }
};
