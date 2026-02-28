<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Make;

use Symfony\Component\Console\Command\Command;

/**
 * Base class for make:* scaffolding commands.
 *
 * Provides shared helpers for stub loading and placeholder replacement.
 */
abstract class AbstractMakeCommand extends Command
{
    /**
     * Convert a snake_case or lower name to PascalCase.
     */
    protected function toPascalCase(string $name): string
    {
        // If already PascalCase (starts with uppercase, no underscores), return as-is.
        if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            return $name;
        }

        return str_replace('_', '', ucwords($name, '_'));
    }

    /**
     * Load a stub file and apply placeholder replacements.
     *
     * @param string $stubName The stub filename without extension (e.g. 'entity').
     * @param array<string, string> $replacements Placeholders => values.
     * @return string The rendered stub content.
     */
    protected function renderStub(string $stubName, array $replacements): string
    {
        $stubPath = dirname(__DIR__, 3) . '/stubs/' . $stubName . '.stub';

        if (!file_exists($stubPath)) {
            throw new \RuntimeException(sprintf('Stub file not found: %s', $stubPath));
        }

        $content = file_get_contents($stubPath);

        foreach ($replacements as $placeholder => $value) {
            $content = str_replace('{{ ' . $placeholder . ' }}', $value, $content);
        }

        return $content;
    }
}
