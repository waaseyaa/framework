<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class SchemaDiagnosticEmitter
{
    /**
     * @param list<array<string, mixed>> $violations
     * @return list<array<string, mixed>>
     */
    public function emit(array $violations): array
    {
        usort($violations, function (array $left, array $right): int {
            $leftIndex = is_int($left['item_index'] ?? null) ? (int) $left['item_index'] : -1;
            $rightIndex = is_int($right['item_index'] ?? null) ? (int) $right['item_index'] : -1;
            $indexCompare = $leftIndex <=> $rightIndex;
            if ($indexCompare !== 0) {
                return $indexCompare;
            }

            $codeCompare = strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
            if ($codeCompare !== 0) {
                return $codeCompare;
            }

            return strcmp($this->pointerTail((string) ($left['location'] ?? '')), $this->pointerTail((string) ($right['location'] ?? '')));
        });

        $diagnostics = [];
        foreach ($violations as $violation) {
            $code = (string) ($violation['code'] ?? 'schema.unknown');
            $value = $violation['value'] ?? null;
            $expected = $violation['expected'] ?? null;

            $context = [];
            $context['value'] = $value;
            $context['expected'] = $expected;
            if (is_string($violation['raw_location'] ?? null) && trim((string) $violation['raw_location']) !== '') {
                $context['raw_location'] = (string) $violation['raw_location'];
            }

            $additional = [];
            foreach ($violation as $key => $rawValue) {
                if (in_array((string) $key, ['code', 'location', 'item_index', 'value', 'expected', 'raw_location'], true)) {
                    continue;
                }
                $additional[(string) $key] = $rawValue;
            }
            ksort($additional);
            foreach ($additional as $key => $rawValue) {
                $context[$key] = $rawValue;
            }

            $diagnostics[] = [
                'code' => $code,
                'message' => $this->messageFor($code, $value, $violation),
                'location' => (string) ($violation['location'] ?? ''),
                'item_index' => is_int($violation['item_index'] ?? null) ? (int) $violation['item_index'] : null,
                'context' => $context,
            ];
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $violation
     */
    private function messageFor(string $code, mixed $value, array $violation): string
    {
        return match ($code) {
            'schema.duplicate_source_uri' => sprintf(
                'Duplicate source_uri detected: "%s". Each item in a batch must have a unique source_uri.',
                (string) $value,
            ),
            'schema.malformed_source_set_uri' => sprintf(
                'Malformed source_set_uri: "%s". Expected format: "<scheme>://<identifier>".',
                (string) $value,
            ),
            'schema.unknown_source_set_scheme' => sprintf(
                'Unknown source_set_uri scheme: "%s". Allowed schemes: %s.',
                (string) $value,
                $this->implodeExpected($violation['allowed_schemes'] ?? []),
            ),
            'schema.missing_required_provenance_field' => sprintf(
                'Missing required provenance field: "%s".',
                (string) ($violation['field_name'] ?? 'unknown'),
            ),
            'schema.missing_required_envelope_field' => sprintf(
                'Missing required envelope field: "%s".',
                (string) ($violation['field_name'] ?? 'unknown'),
            ),
            'schema.invalid_items_type' => sprintf(
                'Invalid items field type: "%s". Expected: "%s".',
                (string) $value,
                (string) ($violation['expected'] ?? ''),
            ),
            'schema.malformed_batch_id' => sprintf(
                'Malformed batch_id value: "%s". Expected: "%s".',
                (string) $value,
                (string) ($violation['expected'] ?? ''),
            ),
            'schema.malformed_source_uri' => sprintf(
                'Malformed source_uri value: "%s". Expected: "%s".',
                (string) $value,
                (string) ($violation['expected'] ?? ''),
            ),
            'schema.invalid_parser_version_type' => sprintf(
                'Invalid parser_version type: "%s". Expected: "%s".',
                (string) $value,
                (string) ($violation['expected'] ?? ''),
            ),
            'schema.malformed_ingested_at' => sprintf(
                'Malformed ingested_at value: "%s". Expected: "%s".',
                (string) $value,
                (string) ($violation['expected'] ?? ''),
            ),
            'schema.invalid_policy_value' => sprintf(
                'Invalid ingestion policy: "%s". Allowed policies: %s.',
                (string) $value,
                $this->implodeExpected($violation['allowed_policies'] ?? []),
            ),
            default => 'Unknown schema validation error.',
        };
    }

    /**
     * @param mixed $values
     */
    private function implodeExpected(mixed $values): string
    {
        if (!is_array($values)) {
            return (string) $values;
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[] = (string) $value;
            }
        }
        sort($normalized);

        return implode(', ', $normalized);
    }

    private function pointerTail(string $pointer): string
    {
        $parts = explode('/', $pointer);
        $tail = end($parts);

        return is_string($tail) ? $tail : '';
    }
}
