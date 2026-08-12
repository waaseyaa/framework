<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Waaseyaa\Foundation\Schema\Diff\CanonicalJson;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/** Forge-neutral identity of the exact executable migration catalogue. */
final readonly class MigrationCatalogFingerprint
{
    /**
     * @param array<string, array<string, Migration>> $legacy
     * @param list<MigrationInterfaceV2>              $v2
     */
    public static function capture(array $legacy, array $v2): string
    {
        $entries = [];
        foreach ($legacy as $package => $migrations) {
            foreach ($migrations as $id => $migration) {
                $source = self::legacySourceChecksum($migration);
                $entries[] = [
                    'id' => $id,
                    'package' => $package,
                    'kind' => 'legacy',
                    'source_checksum' => $source,
                    'plan_hash' => self::legacyPlanHash($source),
                ];
            }
        }
        foreach ($v2 as $migration) {
            $entries[] = [
                'id' => $migration->migrationId(),
                'package' => $migration->package(),
                'kind' => 'v2',
                'dependencies' => $migration->dependencies(),
                'source_checksum' => $migration->plan()->checksum(),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => [
            $left['id'], $left['package'], $left['kind'],
        ] <=> [$right['id'], $right['package'], $right['kind']]);

        return hash('sha256', CanonicalJson::encode([
            'domain' => 'waaseyaa.migration-catalogue.v1',
            'entries' => $entries,
        ]));
    }

    public static function legacySourceChecksum(Migration $migration): string
    {
        $reflection = new \ReflectionClass($migration);
        $file = $reflection->getFileName();
        if (!is_string($file) || !is_file($file)) {
            throw new \RuntimeException('[S1-DB108] Legacy migration source is not readable for strict verification.');
        }
        $lines = file($file);
        if (!is_array($lines)) {
            throw new \RuntimeException('[S1-DB108] Legacy migration source could not be read for strict verification.');
        }
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        return hash('sha256', CanonicalJson::encode([
            'domain' => 'waaseyaa.legacy-migration-source.v1',
            'class' => $reflection->isAnonymous() ? 'anonymous' : $reflection->getName(),
            'source' => str_replace(["\r\n", "\r"], "\n", $source),
        ]));
    }

    public static function legacyPlanHash(string $sourceChecksum): string
    {
        return hash('sha256', "waaseyaa.legacy-procedural-plan.v1\0" . $sourceChecksum);
    }
}
