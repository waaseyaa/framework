<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport;

use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\StructuredImport\Gfm\GfmTableImporter;
use Waaseyaa\StructuredImport\Gfm\GfmTableParser;
use Waaseyaa\StructuredImport\Gfm\PromptNormalizer;

/**
 * Service provider for the StructuredImport package.
 *
 * Binds {@see StructuredImporterInterface} to {@see GfmTableImporter} via a
 * closure that supplies the importer's three constructor dependencies. The
 * previous binding registered the class *name* as a string, which the kernel
 * resolver instantiates with `new $concrete()` (zero args) — fataling with a
 * TypeError the moment the binding was resolved, because the importer requires
 * a field-definition registry, a table parser and a prompt normalizer.
 */
final class StructuredImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(GfmTableParser::class, fn(): GfmTableParser => new GfmTableParser());
        $this->singleton(PromptNormalizer::class, fn(): PromptNormalizer => new PromptNormalizer());

        $this->bind(
            StructuredImporterInterface::class,
            fn(): StructuredImporterInterface => new GfmTableImporter(
                $this->resolve(FieldDefinitionRegistryInterface::class),
                $this->resolve(GfmTableParser::class),
                $this->resolve(PromptNormalizer::class),
            ),
        );
    }
}
