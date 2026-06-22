<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Gfm;

use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\StructuredImport\ImportResult;
use Waaseyaa\StructuredImport\StructuredImporterInterface;
use Waaseyaa\StructuredImport\UnmatchedRow;

/**
 * GFM 2-column table importer.
 *
 * Implements the structured-import pipeline using GFM table parsing with
 * alias-based prompt matching. Normalizes prompts via PromptNormalizer
 * (lowercase + whitespace collapse, no transliteration — C-012).
 *
 * Bundle defaults to entityTypeId when null (FR-013).
 * Field name itself is an implicit alias after normalization.
 * Unmatched rows preserve the original (un-normalized) prompt and value text.
 * Errors from the parser propagate verbatim into ImportResult::$errors.
 *
 * @api
 */
final class GfmTableImporter implements StructuredImporterInterface
{
    public function __construct(
        private readonly FieldDefinitionRegistryInterface $registry,
        private readonly GfmTableParser $parser,
        private readonly PromptNormalizer $normalizer,
    ) {}

    public function import(string $payload, string $entityTypeId, ?string $bundle = null): ImportResult
    {
        $effectiveBundle = $bundle ?? $entityTypeId;
        $fields = $this->registry->bundleFieldsFor($entityTypeId, $effectiveBundle);

        // Build alias index: normalized-alias -> field-name.
        // Field name itself acts as an implicit alias.
        // Last-registered wins on collision (defensive; WP02 compiler validates uniqueness).
        $aliasIndex = [];
        foreach ($fields as $name => $field) {
            $aliasIndex[$this->normalizer->normalize($name)] = $name;
            foreach ($field->getPromptAliases() as $alias) {
                $aliasIndex[$this->normalizer->normalize($alias)] = $name;
            }
        }

        $parseResult = $this->parser->parse($payload);

        $matched = [];
        $unmatched = [];

        foreach ($parseResult->rows as $row) {
            $key = $this->normalizer->normalize($row['prompt']);
            if (isset($aliasIndex[$key])) {
                $matched[$aliasIndex[$key]] = $row['value'];
            } else {
                $unmatched[] = new UnmatchedRow($row['prompt'], $row['value']);
            }
        }

        return new ImportResult(matched: $matched, unmatched: $unmatched, errors: $parseResult->errors);
    }
}
