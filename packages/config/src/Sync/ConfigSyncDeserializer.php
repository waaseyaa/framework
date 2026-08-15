<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\Config\Exception\ConfigSerializationException;

/**
 * Parse a sync-store YAML file into a {@see ConfigSyncFile} value object.
 *
 * Validation pipeline (per contracts/config-manifest.md §Validation pipeline):
 *  1. YAML parses to an array — else `ConfigSerializationException`.
 *  2. `_meta` block exists and has required keys (`entity_type`, `uuid`,
 *     `langcode`).
 *  3. `_meta.entity_type` matches the filename prefix — else
 *     `ConfigSerializationException::entityTypeMismatch()`.
 *  4. Field-presence / `FieldValueMapper` coercion is deferred to
 *     `ConfigSyncValidator` (later WP) which has access to entity-type
 *     `FieldDefinition` sets.
 */
final class ConfigSyncDeserializer
{
    /**
     * Pinned Symfony Yaml parse flags.
     *
     * - PARSE_EXCEPTION_ON_INVALID_TYPE: bail on objects / unknown tags.
     * - We intentionally do not enable PARSE_DATETIME so timestamps come back
     *   as ISO 8601 strings — `FieldValueMapper` then validates/normalises.
     */
    public const PARSE_FLAGS = Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS;

    /**
     * Parse a YAML string into a {@see ConfigSyncFile}.
     *
     * @param non-empty-string $yaml
     * @param non-empty-string $filename basename `<entity_type>.<entity_id>.yml`
     *
     * @throws ConfigSerializationException
     */
    public function fromYaml(string $yaml, string $filename): ConfigSyncFile
    {
        $this->assertLexicallySafe($yaml, $filename);

        try {
            $parsed = Yaml::parse($yaml, self::PARSE_FLAGS);
        } catch (ParseException $exception) {
            throw ConfigSerializationException::malformedYaml($filename, $exception->getMessage());
        }

        if (!\is_array($parsed)) {
            throw ConfigSerializationException::malformedYaml(
                $filename,
                'top-level YAML node must be a mapping, got ' . get_debug_type($parsed),
            );
        }

        $this->assertTypedTree($parsed, $filename, '$');

        /** @var array<string, mixed> $parsed */
        return ConfigSyncFile::fromParsedArray($parsed, $filename);
    }

    private function assertLexicallySafe(string $yaml, string $filename): void
    {
        if ($yaml === '' || preg_match('//u', $yaml) !== 1) {
            throw ConfigSerializationException::malformedYaml($filename, 'input must be non-empty valid UTF-8.');
        }

        /** @var array<string, array<string, true>> $seenKeys */
        $seenKeys = [];
        /** @var list<array{indent: int, token: string}> $parents */
        $parents = [];
        /** @var array<string, int> $sequenceCounters */
        $sequenceCounters = [];
        $blockScalarIndent = null;

        $lines = preg_split('/\R/', $yaml);
        if ($lines === false) {
            throw ConfigSerializationException::malformedYaml($filename, 'input lines could not be tokenized.');
        }
        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, "\t")) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('tabs are forbidden at line %d.', $lineNumber + 1));
            }
            $indent = \strlen($line) - \strlen(ltrim($line, ' '));
            if ($blockScalarIndent !== null) {
                if (trim($line) === '' || $indent > $blockScalarIndent) {
                    continue;
                }
                $blockScalarIndent = null;
            }
            $lexical = $this->stripQuotedScalarsAndComment($line);
            $trimmed = trim($lexical);
            if ($trimmed === '') {
                continue;
            }
            if (str_starts_with($trimmed, '%')) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('directives are forbidden at line %d.', $lineNumber + 1));
            }
            if (preg_match('/(?:^|[\s\[\]{},:])(?:&|\*)[A-Za-z0-9_-]+/', $lexical) === 1
                || preg_match('/^\s*(?:-\s+)?<<\s*:/', $lexical) === 1
                || preg_match('/(?:^|[\s\[\]{},:])![^\s]+/', $lexical) === 1
            ) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('anchors, aliases, merge keys, and tags are forbidden at line %d.', $lineNumber + 1));
            }
            if (preg_match('/(?:^|:\s+|-\s+|[\[,]\s*)\d{4}-\d{2}-\d{2}(?:[Tt ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?(?=\s|$|[\],}])/', $lexical) === 1) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('implicit date or timestamp scalar is forbidden at line %d.', $lineNumber + 1));
            }

            while ($parents !== [] && $parents[array_key_last($parents)]['indent'] >= $indent) {
                array_pop($parents);
            }
            $mapping = $this->extractMappingKey($line);
            $sequence = $mapping['sequence'] ?? preg_match('/^\s*-\s+/', $lexical) === 1;
            if ($sequence) {
                $parentId = implode('/', array_column($parents, 'token'));
                $sequenceKey = $parentId . '@' . $indent;
                $sequenceCounters[$sequenceKey] = ($sequenceCounters[$sequenceKey] ?? 0) + 1;
                $parents[] = ['indent' => $indent, 'token' => '#' . $sequenceCounters[$sequenceKey]];
            }
            if ($mapping === null) {
                continue;
            }
            $key = $mapping['key'];
            $context = implode('/', array_column($parents, 'token'));
            if (isset($seenKeys[$context][$key])) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('duplicate mapping key "%s" at line %d.', $key, $lineNumber + 1));
            }
            $seenKeys[$context][$key] = true;
            $valueLexical = trim($this->stripQuotedScalarsAndComment($mapping['rest']));
            if (preg_match('/^[|>][+-]?(?:[1-9])?$/', $valueLexical) === 1) {
                $blockScalarIndent = $indent;
            }
            if ($valueLexical === '') {
                $parents[] = ['indent' => $indent + ($sequence ? 2 : 0), 'token' => $key];
            }
        }
    }

    /** @return array{key: string, rest: string, sequence: bool}|null */
    private function extractMappingKey(string $line): ?array
    {
        $body = ltrim($line, ' ');
        $sequence = preg_match('/^-\s+/', $body, $sequenceMatch) === 1;
        if ($sequence) {
            $body = substr($body, \strlen($sequenceMatch[0]));
        }
        if ($body === '') {
            return null;
        }
        if ($body[0] === "'") {
            $key = '';
            $length = \strlen($body);
            for ($index = 1; $index < $length; ++$index) {
                if ($body[$index] !== "'") {
                    $key .= $body[$index];
                    continue;
                }
                if (($body[$index + 1] ?? null) === "'") {
                    $key .= "'";
                    ++$index;
                    continue;
                }
                $rest = ltrim(substr($body, $index + 1));

                return str_starts_with($rest, ':')
                    ? ['key' => $key, 'rest' => substr($rest, 1), 'sequence' => $sequence]
                    : null;
            }

            return null;
        }
        if ($body[0] === '"') {
            $escaped = false;
            $length = \strlen($body);
            for ($index = 1; $index < $length; ++$index) {
                if ($body[$index] === '\\' && !$escaped) {
                    $escaped = true;
                    continue;
                }
                if ($body[$index] === '"' && !$escaped) {
                    $encodedKey = substr($body, 0, $index + 1);
                    try {
                        $key = json_decode($encodedKey, true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        return null;
                    }
                    $rest = ltrim(substr($body, $index + 1));

                    return \is_string($key) && str_starts_with($rest, ':')
                        ? ['key' => $key, 'rest' => substr($rest, 1), 'sequence' => $sequence]
                        : null;
                }
                $escaped = false;
            }

            return null;
        }
        $plain = $this->stripQuotedScalarsAndComment($body);
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*|<<)\s*:(.*)$/', $plain, $match) !== 1) {
            return null;
        }

        $colon = strpos($body, ':');

        return $colon === false
            ? null
            : ['key' => $match[1], 'rest' => substr($body, $colon + 1), 'sequence' => $sequence];
    }

    private function stripQuotedScalarsAndComment(string $line): string
    {
        $result = '';
        $quote = null;
        $escaped = false;
        $length = \strlen($line);
        for ($index = 0; $index < $length; ++$index) {
            $character = $line[$index];
            if ($quote !== null) {
                if ($quote === '"' && $character === '\\' && !$escaped) {
                    $escaped = true;
                    $result .= ' ';
                    continue;
                }
                if ($character === $quote && !$escaped) {
                    $quote = null;
                }
                $escaped = false;
                $result .= ' ';
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $result .= ' ';
                continue;
            }
            if ($character === '#') {
                break;
            }
            $result .= $character;
        }

        return $result;
    }

    private function assertTypedTree(mixed $value, string $filename, string $path): void
    {
        if (\is_float($value)) {
            throw ConfigSerializationException::malformedYaml($filename, sprintf('floating-point value at %s is forbidden.', $path));
        }
        if (\is_int($value) && ($value < -9_007_199_254_740_991 || $value > 9_007_199_254_740_991)) {
            throw ConfigSerializationException::malformedYaml($filename, sprintf('integer at %s exceeds the interoperable range.', $path));
        }
        if (\is_string($value) && preg_match('//u', $value) !== 1) {
            throw ConfigSerializationException::malformedYaml($filename, sprintf('string at %s is not valid UTF-8.', $path));
        }
        if (!\is_array($value)) {
            if ($value !== null && !\is_bool($value) && !\is_int($value) && !\is_string($value)) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('value at %s has forbidden type %s.', $path, get_debug_type($value)));
            }

            return;
        }
        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            if (!$isList && (!\is_string($key) || $key === '' || preg_match('/^[\x20-\x7E]+$/D', $key) !== 1)) {
                throw ConfigSerializationException::malformedYaml($filename, sprintf('mapping key at %s must be non-empty ASCII.', $path));
            }
            $this->assertTypedTree($child, $filename, $path . '.' . (string) $key);
        }
    }
}
