<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class SchemaValidator
{
    /** @var list<string> */
    private const array ALLOWED_POLICIES = ['atomic_fail_fast', 'validate_only'];

    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['dataset', 'crawl', 'manual', 'api', 'file'];

    /**
     * @param array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    public function validate(array $envelope): array
    {
        $violations = [];

        $batchId = is_string($envelope['batch_id'] ?? null) ? trim((string) $envelope['batch_id']) : '';
        if ($batchId === '') {
            $violations[] = [
                'code' => 'schema.missing_required_envelope_field',
                'location' => '/batch_id',
                'item_index' => null,
                'value' => null,
                'expected' => 'non-empty string',
                'field_name' => 'batch_id',
            ];
        } elseif (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $batchId) !== 1) {
            $violations[] = [
                'code' => 'schema.malformed_batch_id',
                'location' => '/batch_id',
                'item_index' => null,
                'value' => $batchId,
                'expected' => '^[a-z0-9][a-z0-9_-]*$',
            ];
        }

        $policy = is_string($envelope['policy'] ?? null) ? (string) $envelope['policy'] : '';
        if ($policy === '') {
            $violations[] = [
                'code' => 'schema.missing_required_envelope_field',
                'location' => '/policy',
                'item_index' => null,
                'value' => null,
                'expected' => 'non-empty string',
                'field_name' => 'policy',
            ];
        }
        if (!in_array($policy, self::ALLOWED_POLICIES, true)) {
            $violations[] = [
                'code' => 'schema.invalid_policy_value',
                'location' => '/policy',
                'item_index' => null,
                'value' => $policy,
                'expected' => self::ALLOWED_POLICIES,
                'allowed_policies' => self::ALLOWED_POLICIES,
            ];
        }

        $sourceSetUri = is_string($envelope['source_set_uri'] ?? null) ? (string) $envelope['source_set_uri'] : '';
        if ($sourceSetUri === '') {
            $violations[] = [
                'code' => 'schema.missing_required_envelope_field',
                'location' => '/source_set_uri',
                'item_index' => null,
                'value' => null,
                'expected' => 'non-empty string',
                'field_name' => 'source_set_uri',
            ];
        }
        if (!$this->isValidSourceSetUriFormat($sourceSetUri)) {
            $violations[] = [
                'code' => 'schema.malformed_source_set_uri',
                'location' => '/source_set_uri',
                'item_index' => null,
                'value' => $sourceSetUri,
                'expected' => '<scheme>://<identifier>',
            ];
        } else {
            $scheme = $this->extractSourceSetScheme($sourceSetUri);
            if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
                $violations[] = [
                    'code' => 'schema.unknown_source_set_scheme',
                    'location' => '/source_set_uri',
                    'item_index' => null,
                    'value' => $scheme,
                    'expected' => self::ALLOWED_SCHEMES,
                    'allowed_schemes' => self::ALLOWED_SCHEMES,
                ];
            }
        }

        if (!is_array($envelope['items'] ?? null)) {
            $violations[] = [
                'code' => 'schema.invalid_items_type',
                'location' => '/items',
                'item_index' => null,
                'value' => gettype($envelope['items'] ?? null),
                'expected' => 'array',
            ];

            return $violations;
        }

        $items = $envelope['items'];
        $seenSourceUris = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                $violations[] = [
                    'code' => 'schema.missing_required_provenance_field',
                    'location' => '/items/' . $index,
                    'item_index' => $index,
                    'value' => null,
                    'expected' => 'object',
                    'field_name' => 'item',
                ];
                continue;
            }

            $sourceUri = is_string($item['source_uri'] ?? null) ? (string) $item['source_uri'] : '';
            if ($sourceUri === '') {
                $violations[] = [
                    'code' => 'schema.missing_required_provenance_field',
                    'location' => '/items/' . $index . '/source_uri',
                    'item_index' => $index,
                    'value' => null,
                    'expected' => 'non-empty string',
                    'field_name' => 'source_uri',
                ];
            } elseif (!$this->isValidSourceSetUriFormat($sourceUri)) {
                $violations[] = [
                    'code' => 'schema.malformed_source_uri',
                    'location' => '/items/' . $index . '/source_uri',
                    'item_index' => $index,
                    'value' => $sourceUri,
                    'expected' => '<scheme>://<identifier>',
                ];
            } elseif (isset($seenSourceUris[$sourceUri])) {
                $violations[] = [
                    'code' => 'schema.duplicate_source_uri',
                    'location' => '/items/' . $index . '/source_uri',
                    'item_index' => $index,
                    'value' => $sourceUri,
                    'expected' => 'unique within batch',
                    'duplicate_with' => $seenSourceUris[$sourceUri],
                ];
            } else {
                $seenSourceUris[$sourceUri] = $index;
            }

            $ingestedAt = $item['ingested_at'] ?? null;
            if ($ingestedAt === null || $ingestedAt === '') {
                $violations[] = [
                    'code' => 'schema.missing_required_provenance_field',
                    'location' => '/items/' . $index . '/ingested_at',
                    'item_index' => $index,
                    'value' => null,
                    'expected' => 'non-empty value',
                    'field_name' => 'ingested_at',
                ];
            } elseif (!$this->isValidIngestedAt($ingestedAt)) {
                $violations[] = [
                    'code' => 'schema.malformed_ingested_at',
                    'location' => '/items/' . $index . '/ingested_at',
                    'item_index' => $index,
                    'value' => is_scalar($ingestedAt) ? (string) $ingestedAt : null,
                    'expected' => 'unix_timestamp_or_iso8601',
                ];
            }

            $parserVersion = $item['parser_version'] ?? null;
            if ($parserVersion !== null && !is_string($parserVersion)) {
                $violations[] = [
                    'code' => 'schema.invalid_parser_version_type',
                    'location' => '/items/' . $index . '/parser_version',
                    'item_index' => $index,
                    'value' => gettype($parserVersion),
                    'expected' => 'string_or_null',
                ];
            }
        }

        return $violations;
    }

    private function isValidSourceSetUriFormat(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:\/\/.+$/i', $value) === 1;
    }

    private function extractSourceSetScheme(string $uri): string
    {
        $parts = explode('://', $uri, 2);
        return strtolower(trim($parts[0] ?? ''));
    }

    private function isValidIngestedAt(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        if (ctype_digit($trimmed)) {
            return true;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}:\d{2}(?:Z)?)?$/', $trimmed) === 1;
    }
}
