<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

abstract class Migration
{
    /** @var list<string> Package names this migration must run after */
    public array $after = [];

    abstract public function up(SchemaBuilder $schema): void;

    public function down(SchemaBuilder $schema): void {}
}
