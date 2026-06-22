<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Sync;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;

/**
 * Generic sync orchestrator: fetch NC hits, dedup, delegate mapping, persist.
 *
 * Mappers are resolved from MapperRegistry. Multiple mappers may fire on the same
 * hit — every mapper whose supports() returns true produces an entity.
 */
final class NcSyncService
{
    private const string SKIP_REASON_NO_MAPPER = 'no_mapper_supported';
    private const string SKIP_REASON_DUPLICATE = 'duplicate_dedup';

    /**
     * @param list<string> $topics NC topic filters
     */
    public function __construct(
        private readonly NorthCloudClient $client,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly MapperRegistry $mappers,
        private readonly array $topics = ['indigenous'],
        private readonly int $minQuality = 60,
    ) {}

    /**
     * Fetch recent NC content and persist matching entities.
     *
     * @param int $limit Max hits to fetch
     * @param string|null $since ISO date lower bound (YYYY-MM-DD)
     * @param bool $dryRun When true, count what would be created without persisting
     * @param bool $explain Include skip reason details in result payloads
     * @param int $sampleLimit Capture up to N sample created/skipped hit summaries
     */
    public function sync(
        int $limit = 20,
        ?string $since = null,
        bool $dryRun = false,
        bool $explain = false,
        int $sampleLimit = 0,
    ): NcSyncResult {
        $response = $this->client->getRecentContent(
            limit: $limit,
            since: $since,
            topics: $this->topics,
            minQuality: $this->minQuality,
        );

        if ($response === null) {
            error_log('NcSyncService: failed to fetch content from NorthCloud');
            return new NcSyncResult()->withFetchFailed();
        }

        $hits = $response['hits'];
        $result = new NcSyncResult()->withFetched(\count($hits));

        foreach ($hits as $hit) {
            if (!\is_array($hit)) {
                error_log('NcSyncService: skipping malformed hit item');
                $result = $result->withFailed();
                continue;
            }

            $result = $this->processHit($hit, $dryRun, $explain, $sampleLimit, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function processHit(
        array $hit,
        bool $dryRun,
        bool $explain,
        int $sampleLimit,
        NcSyncResult $result,
    ): NcSyncResult {
        $supportDiagnostics = [];
        $supportingMappers = $this->resolveSupportingMappers($hit, $supportDiagnostics);

        if ($supportingMappers === []) {
            $skipReason = $this->deriveSkipReason($supportDiagnostics);

            $result = $result
                ->withSkipped()
                ->withSkipReason($skipReason);

            if ($explain || $sampleLimit > 0) {
                $sample = $this->summarizeHit($hit) + ['reason' => $skipReason];
                if ($supportDiagnostics !== []) {
                    $sample['diagnostics'] = $supportDiagnostics;
                }
                $result = $result->withSkippedSample($sample, $sampleLimit);
            }

            return $result;
        }

        foreach ($supportingMappers as $mapper) {
            $result = $this->applyMapper($mapper, $hit, $dryRun, $sampleLimit, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function applyMapper(
        NcHitToEntityMapperInterface $mapper,
        array $hit,
        bool $dryRun,
        int $sampleLimit,
        NcSyncResult $result,
    ): NcSyncResult {
        try {
            $entityType = $mapper->entityType();
            $fields = $mapper->map($hit);
            $dedupField = $mapper->dedupField();
            $storage = $this->entityTypeManager->getStorage($entityType);

            if ($dedupField !== '') {
                if (!array_key_exists($dedupField, $fields)) {
                    throw new \LogicException(sprintf(
                        "Mapper %s declares dedupField '%s' but that key is not in map() output",
                        $mapper::class,
                        $dedupField,
                    ));
                }

                if ($fields[$dedupField] !== '' && $fields[$dedupField] !== null) {
                    // Background sync runs with no per-user account; bind the dedup-existence
                    // lookup with accessCheck(false) so it checks ALL rows for a duplicate (a
                    // duplicate is a duplicate regardless of visibility) instead of throwing
                    // MissingQueryAccountException on the real SqlEntityStorage (B-5).
                    $existing = $storage->getQuery()
                        ->accessCheck(false)
                        ->condition($dedupField, $fields[$dedupField])
                        ->execute();

                    if ($existing !== []) {
                        $sample = $this->summarizeHit($hit) + [
                            'reason' => self::SKIP_REASON_DUPLICATE,
                            'entity_type' => $entityType,
                            'dedup_field' => $dedupField,
                            'dedup_value' => (string) $fields[$dedupField],
                        ];

                        return $result
                            ->withSkipped()
                            ->withSkipReason(self::SKIP_REASON_DUPLICATE)
                            ->withSkippedSample($sample, $sampleLimit);
                    }
                }
            }

            if ($dryRun) {
                $sample = $this->summarizeHit($hit) + [
                    'entity_type' => $entityType,
                    'mapped_title' => self::scalarOrNull($fields['title'] ?? null),
                    'mapped_slug' => self::scalarOrNull($fields['slug'] ?? null),
                ];

                return $result
                    ->withCreated()
                    ->withCreatedSample($sample, $sampleLimit);
            }

            $entity = $storage->create($fields);
            $storage->save($entity);
            $sample = $this->summarizeHit($hit) + [
                'entity_type' => $entityType,
                'mapped_title' => self::scalarOrNull($fields['title'] ?? null),
                'mapped_slug' => self::scalarOrNull($fields['slug'] ?? null),
            ];

            return $result
                ->withCreated()
                ->withCreatedSample($sample, $sampleLimit);
        } catch (\LogicException $e) {
            // Contract violation — rethrow so mapper bugs surface loudly.
            throw $e;
        } catch (\Throwable $e) {
            $entityType ??= $mapper->entityType();
            $dedupField ??= $mapper->dedupField();
            $fields ??= [];
            $source = ($dedupField !== '' && isset($fields[$dedupField])) ? (string) $fields[$dedupField] : '(no dedup key)';
            error_log(sprintf(
                'NcSyncService: failed to create %s from %s: %s',
                $entityType,
                $source,
                $e->getMessage(),
            ));
            return $result->withFailed();
        }
    }

    /**
     * @param array<string, mixed> $hit
     * @param list<array<string, mixed>> $diagnostics
     * @return list<NcHitToEntityMapperInterface>
     */
    private function resolveSupportingMappers(array $hit, array &$diagnostics): array
    {
        $supporting = [];

        foreach ($this->mappers->all() as $mapper) {
            if ($mapper instanceof NcHitSupportDiagnosticsInterface) {
                $decision = $mapper->diagnoseSupport($hit);
                $supported = $decision['supported'];

                if (!$supported) {
                    $diagnostics[] = [
                        'mapper' => $mapper::class,
                        'reason' => $decision['reason'] ?? self::SKIP_REASON_NO_MAPPER,
                        'details' => $decision['details'] ?? [],
                    ];
                    continue;
                }

                $supporting[] = $mapper;
                continue;
            }

            if ($mapper->supports($hit)) {
                $supporting[] = $mapper;
            }
        }

        return $supporting;
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    private function deriveSkipReason(array $diagnostics): string
    {
        if (count($diagnostics) !== 1) {
            return self::SKIP_REASON_NO_MAPPER;
        }

        $reason = $diagnostics[0]['reason'] ?? self::SKIP_REASON_NO_MAPPER;
        return is_string($reason) && $reason !== '' ? $reason : self::SKIP_REASON_NO_MAPPER;
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, scalar|null>
     */
    private function summarizeHit(array $hit): array
    {
        return [
            'id' => self::scalarOrNull($hit['id'] ?? null),
            'title' => self::scalarOrNull($hit['title'] ?? null),
            'url' => self::scalarOrNull($hit['url'] ?? null),
            'source_name' => self::scalarOrNull($hit['source_name'] ?? null),
            'quality_score' => self::scalarOrNull($hit['quality_score'] ?? null),
            'topics' => is_array($hit['topics'] ?? null) ? implode(', ', array_map(strval(...), $hit['topics'])) : null,
            'published_date' => self::scalarOrNull($hit['published_date'] ?? null),
        ];
    }

    private static function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }
}
