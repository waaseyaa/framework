<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Ingestion;

final class CrossSourceIdentityResolver
{
    private const OWNERSHIP_PRIORITY = [
        'first_party' => 0,
        'federated' => 1,
        'third_party' => 2,
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows:list<array<string, mixed>>,diagnostics:list<array<string, mixed>>}
     */
    public function resolve(array $rows): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row['source_uri']) || (string) $row['source_uri'] === '') {
                continue;
            }

            $fingerprint = $this->fingerprint((string) $row['source_uri']);
            $groups[$fingerprint][] = ['index' => $index, 'row' => $row];
        }

        ksort($groups);

        $resolved = [];
        $diagnostics = [];
        foreach ($groups as $fingerprint => $members) {
            usort(
                $members,
                fn(array $left, array $right): int => $this->compareMembers($left['row'], $right['row']),
            );

            $canonical = $members[0]['row'];
            $canonicalId = hash('sha256', $fingerprint);
            $memberSourceIds = [];

            foreach ($members as $member) {
                $row = $member['row'];
                $row['canonical_id'] = $canonicalId;
                $resolved[] = $row;
                $memberSourceIds[] = (string) ($row['source_id'] ?? '');
            }

            sort($memberSourceIds);

            $diagnostics[] = [
                'code' => count($members) > 1 ? 'identity.collision_resolved' : 'identity.canonical_binding',
                'message' => count($members) > 1
                    ? 'Resolved cross-source identity collision using deterministic ownership priority.'
                    : 'Bound source row to canonical identity.',
                'location' => '/identity/' . $canonicalId,
                'item_index' => null,
                'context' => [
                    'canonical_id' => $canonicalId,
                    'canonical_source_id' => (string) ($canonical['source_id'] ?? ''),
                    'member_source_ids' => $memberSourceIds,
                ],
            ];
        }

        usort(
            $resolved,
            static fn(array $a, array $b): int => strcmp((string) ($a['canonical_id'] ?? ''), (string) ($b['canonical_id'] ?? ''))
                ?: strcmp((string) ($a['source_id'] ?? ''), (string) ($b['source_id'] ?? '')),
        );

        return ['rows' => $resolved, 'diagnostics' => $diagnostics];
    }

    private function fingerprint(string $sourceUri): string
    {
        $parts = parse_url($sourceUri);
        if (!is_array($parts)) {
            return strtolower($sourceUri);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? ''));

        return $host . $path;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareMembers(array $left, array $right): int
    {
        $leftPriority = self::OWNERSHIP_PRIORITY[(string) ($left['ownership'] ?? 'third_party')] ?? 99;
        $rightPriority = self::OWNERSHIP_PRIORITY[(string) ($right['ownership'] ?? 'third_party')] ?? 99;

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string) ($left['source_id'] ?? ''), (string) ($right['source_id'] ?? ''));
    }
}
