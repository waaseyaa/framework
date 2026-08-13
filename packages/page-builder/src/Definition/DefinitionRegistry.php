<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Definition;

use Waaseyaa\PageBuilder\Definition\Exception\DuplicateDefinitionException;
use Waaseyaa\PageBuilder\Definition\Exception\UnknownDefinitionException;

/** @api */
final class DefinitionRegistry
{
    /** @var array<string, BlockDefinition> */
    private array $blocks = [];

    /** @var array<string, LayoutDefinition> */
    private array $layouts = [];

    /** @var array<string, TemplateDefinition> */
    private array $templates = [];

    public function registerBlock(BlockDefinition $definition): void
    {
        $this->register($this->blocks, $definition->id, $definition->version, $definition, 'block');
    }

    public function registerLayout(LayoutDefinition $definition): void
    {
        $this->register($this->layouts, $definition->id, $definition->version, $definition, 'layout');
    }

    public function registerTemplate(TemplateDefinition $definition): void
    {
        $this->register($this->templates, $definition->id, $definition->version, $definition, 'template');
    }

    public function block(string $id, int $version): BlockDefinition
    {
        return $this->get($this->blocks, $id, $version, 'block');
    }

    public function layout(string $id, int $version): LayoutDefinition
    {
        return $this->get($this->layouts, $id, $version, 'layout');
    }

    public function template(string $id, int $version): TemplateDefinition
    {
        return $this->get($this->templates, $id, $version, 'template');
    }

    /** @param array<string, object> $definitions */
    private function register(array &$definitions, string $id, int $version, object $definition, string $kind): void
    {
        $key = $this->key($id, $version);
        if (isset($definitions[$key])) {
            throw new DuplicateDefinitionException("Duplicate {$kind} definition: {$id}@{$version}");
        }
        $definitions[$key] = $definition;
        ksort($definitions, SORT_STRING);
    }

    /**
     * @template T of object
     * @param array<string, T> $definitions
     * @return T
     */
    private function get(array $definitions, string $id, int $version, string $kind): object
    {
        $key = $this->key($id, $version);
        if (!isset($definitions[$key])) {
            throw new UnknownDefinitionException("Unknown {$kind} definition: {$id}@{$version}");
        }

        return $definitions[$key];
    }

    private function key(string $id, int $version): string
    {
        return $id . '@' . $version;
    }
}
