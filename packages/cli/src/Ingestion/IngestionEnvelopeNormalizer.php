<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class IngestionEnvelopeNormalizer
{
    /**
     * @param array<string, mixed> $envelope
     * @return array{envelope: array<string, mixed>}
     */
    public function normalize(array $envelope): array
    {
        $normalized = [
            'batch_id' => $this->trimString($envelope['batch_id'] ?? null),
            'source_set_uri' => $this->trimString($envelope['source_set_uri'] ?? null),
            'policy' => strtolower($this->trimString($envelope['policy'] ?? null)),
            'items' => [],
        ];

        $items = is_array($envelope['items'] ?? null) ? array_values($envelope['items']) : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $normalized['items'][] = [
                    'source_uri' => '',
                    'ingested_at' => null,
                    'parser_version' => null,
                ];
                continue;
            }

            $normalizedItem = [
                'source_uri' => $this->trimString($item['source_uri'] ?? null),
                'ingested_at' => $this->normalizeIngestedAt($item['ingested_at'] ?? null),
                'parser_version' => $this->normalizeParserVersion($item['parser_version'] ?? null),
            ];

            foreach ($item as $key => $value) {
                if (in_array((string) $key, ['source_uri', 'ingested_at', 'parser_version'], true)) {
                    continue;
                }

                if (is_string($value)) {
                    $normalizedItem[(string) $key] = trim($value);
                    continue;
                }

                $normalizedItem[(string) $key] = $value;
            }

            $normalized['items'][] = $normalizedItem;
        }

        return ['envelope' => $normalized];
    }

    private function trimString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function normalizeIngestedAt(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
            return $trimmed;
        }

        return null;
    }

    private function normalizeParserVersion(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = is_string($value) ? trim($value) : '';
        return $trimmed === '' ? null : $trimmed;
    }
}
