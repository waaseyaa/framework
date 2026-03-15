<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

final class TableBuilder
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{columns: list<string>}> */
    private array $indexes = [];

    /** @var list<array{columns: list<string>}> */
    private array $uniqueIndexes = [];

    private ?array $primaryKey = null;

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->string($name, 128);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'string', $length);
        $this->columns[] = $col;
        return $col;
    }

    public function text(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'text');
        $this->columns[] = $col;
        return $col;
    }

    public function integer(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'integer');
        $this->columns[] = $col;
        return $col;
    }

    public function boolean(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'boolean');
        $this->columns[] = $col;
        return $col;
    }

    public function float(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'float');
        $this->columns[] = $col;
        return $col;
    }

    public function json(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'json');
        $this->columns[] = $col;
        return $col;
    }

    public function timestamp(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'datetime_immutable');
        $this->columns[] = $col;
        return $col;
    }

    public function timestamps(): void
    {
        $this->timestamp('created');
        $this->timestamp('changed');
    }

    public function primary(array $columns): void
    {
        $this->primaryKey = $columns;
    }

    public function unique(array|string $columns): void
    {
        $this->uniqueIndexes[] = ['columns' => (array) $columns];
    }

    public function index(array|string $columns, ?string $name = null): void
    {
        $this->indexes[] = ['columns' => (array) $columns, 'name' => $name];
    }

    public function entityBase(): void
    {
        $this->id();
        $this->string('entity_type', 64);
        $this->string('bundle', 64);
        $this->json('_data')->nullable();
        $this->timestamps();
    }

    public function translationColumns(): void
    {
        $this->string('langcode', 12);
        $this->boolean('default_langcode')->default(true);
        $this->string('translation_source', 12)->nullable();
    }

    public function revisionColumns(): void
    {
        $this->string('revision_id', 128);
        $this->timestamp('revision_created');
        $this->text('revision_log')->nullable();
    }

    /** @return list<ColumnDefinition> */
    public function getColumns(): array
    {
        return $this->columns;
    }
    public function getIndexes(): array
    {
        return $this->indexes;
    }
    public function getUniqueIndexes(): array
    {
        return $this->uniqueIndexes;
    }
    public function getPrimaryKey(): ?array
    {
        return $this->primaryKey;
    }
}
