<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

abstract class Migration
{
    /** @var list<string> Package names this migration must run after */
    public array $after = [];

    abstract public function up(SchemaBuilder $schema): void;

    /**
     * Explicit legacy reverse-plan opt-in (#2731).
     *
     * Defaults to false (forward-only). Test fixtures and new migrations may
     * return true. Checksum-bound first-party historical files must not add
     * this method — register supported ids in {@see LegacyReversePlanCatalog}.
     */
    public function providesSupportedReverse(): bool
    {
        return false;
    }

    public function down(SchemaBuilder $schema): void {}
}
