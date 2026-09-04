<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Doctor\ArchitectureScanner;
use Waaseyaa\SiteContract\Doctor\DoctorSuppressionSet;
use Waaseyaa\SiteContract\Doctor\FindingSeverity;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;
use Waaseyaa\SiteContract\Doctor\SiteDoctorReport;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final class SiteDoctorService
{
    public function inspect(string $projectRoot, ?\DateTimeImmutable $today = null): SiteDoctorReport
    {
        return $this->inspectProject($projectRoot, $today);
    }

    /** Compatibility alias for unit-aware inspection. */
    public function inspectUnits(string $projectRoot, ?\DateTimeImmutable $today = null): SiteDoctorReport
    {
        return $this->inspect($projectRoot, $today);
    }

    private function inspectProject(string $projectRoot, ?\DateTimeImmutable $today): SiteDoctorReport
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Site doctor requires an existing project root.');
        }
        $manifestPath = $root . '/.waaseyaa/site.yaml';
        $manifestBytes = $this->readRequired($manifestPath, '.waaseyaa/site.yaml');
        $manifest = new SiteManifestParser()->parse($manifestBytes, '.waaseyaa/site.yaml');
        $snapshot = new ProjectSourceDiscovery()->discover($root);
        $findings = new ArchitectureScanner()->scan($snapshot->sources);
        $findings = [...$findings, ...$this->provenanceFindings($root, $manifest->framework->observedLockSha256), ...$this->generatedUnitArtifactFindings($root, $manifest)];
        $suppressed = [];
        $suppressionPath = $root . '/.waaseyaa/site-doctor-suppressions.yaml';
        if (is_file($suppressionPath)) {
            $application = new DoctorSuppressionSet()->apply($this->readRequired($suppressionPath, '.waaseyaa/site-doctor-suppressions.yaml'), $findings, $snapshot->digest, $today ?? new \DateTimeImmutable('today'));
            $findings = $application->findings;
            $suppressed = $application->suppressed;
        }

        $lockDigest = is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : false;
        $metadataDigest = is_file($root . '/.waaseyaa/generated.json') ? hash_file('sha256', $root . '/.waaseyaa/generated.json') : false;

        return SiteDoctorReport::generation(
            $manifest->digest,
            $snapshot->digest,
            $findings,
            $suppressed,
            is_string($lockDigest) ? $lockDigest : str_repeat('0', 64),
            is_string($metadataDigest) ? $metadataDigest : str_repeat('0', 64),
        );
    }

    /** @return list<SiteDoctorFinding> */
    private function provenanceFindings(string $root, string $expectedLockDigest): array
    {
        $path = $root . '/composer.lock';
        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if (is_string($actual) && hash_equals($expectedLockDigest, $actual)) {
            return [];
        }

        return [$this->finding('SITE011_COMPOSER_LOCK_DRIFT', 'composer.lock', 1, 'Composer lock identity does not match the manifest.', 'Regenerate the manifest through site:init after reviewing the exact dependency lock.', (string) $actual)];
    }

    /** @return list<SiteDoctorFinding> */
    private function generatedUnitArtifactFindings(string $root, \Waaseyaa\SiteContract\SiteManifest $manifest): array
    {
        try {
            // Reading and target admission belong to the existing execution
            // authority, including every carried path; doctor owns no parser.
            $authority = new SiteInitializationService($root);
            $metadata = $authority->readUnitMetadata();
        } catch (\Throwable) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated unit ownership metadata is missing or invalid.', 'Restore the governed ownership metadata.', 'invalid-units')];
        }
        $projection = $metadata;
        unset($projection['units'], $projection['registrations']);
        $projection['artifacts'] = array_values(array_filter($metadata['artifacts'], static fn(array $row): bool => !array_key_exists('unit', $row)));
        $expected = SiteArtifactRendererFactory::create()->render($manifest)->artifacts['.waaseyaa/generated.json']->content;
        if (!hash_equals($expected, CanonicalJson::encode($projection) . "\n")) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, '[unit site; disposition managed] Generated root ownership was substituted or does not match its manifest.', 'Restore the root generated artifact set.', CanonicalJson::encode($projection))];
        }
        $units = [];
        foreach ($metadata['units'] ?? [] as $unit) {
            $units[$unit['id']] = $unit['disposition'];
        }
        $findings = $this->registrationFindings($authority, $metadata, $units);
        foreach ($metadata['artifacts'] as $row) {
            $unit = $row['unit'] ?? 'site';
            $disposition = $units[$unit] ?? 'managed';
            $context = "[unit {$unit}; disposition {$disposition}] ";
            $path = $root . '/' . $row['path'];
            $content = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($content)) {
                $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', $row['path'], 1, $context . 'Recorded artifact is missing or unreadable.', 'Restore the recorded artifact.', 'missing');
                continue;
            }
            try {
                $actual = new GeneratedArtifact($row['path'], $content, intval($row['mode'], 8), $row['extension_region'] ?? null)->managedDigest();
            } catch (\Throwable) {
                $actual = '';
            }
            if (!hash_equals($row['managed_sha256'], $actual)) {
                if ($disposition === 'seeded') {
                    $id = 'SITE013_SEEDED_ARTIFACT_MODIFIED';
                    $findings[] = new SiteDoctorFinding($id, FindingSeverity::Warning, $row['path'], 1, $context . 'Seeded artifact was modified since generation.', 'Review the developer-owned changes; regeneration does not overwrite them.', hash('sha256', $id . "\0" . $row['path'] . "\0" . $content));
                } else {
                    $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', $row['path'], 1, $context . 'Managed artifact bytes were substituted.', 'Restore the generated artifact; use only declared extension regions for local changes.', $actual);
                }
            }
            $mode = DIRECTORY_SEPARATOR === '/' ? fileperms($path) : null;
            if ($mode !== null && ($mode === false || sprintf('%04o', $mode & 0o777) !== $row['mode'])) {
                $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', $row['path'], 1, $context . 'Recorded artifact mode was substituted.', 'Restore the recorded mode.', is_int($mode) ? sprintf('%04o', $mode & 0o777) : 'unreadable');
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, string> $units
     * @return list<SiteDoctorFinding>
     */
    private function registrationFindings(SiteInitializationService $authority, array $metadata, array $units): array
    {
        try {
            $state = $authority->readComposerProviderState();
        } catch (\Throwable) {
            return [$this->finding('SITE015_REGISTRATION_DRIFT', 'composer.json', 1, 'Composer provider state is invalid or unsafe to inspect.', 'Restore a valid unique provider list and a private regular Composer manifest.', 'invalid-provider-state')];
        }
        $findings = [];
        foreach ($metadata['registrations'] ?? [] as $row) {
            if (in_array($row['fqcn'], $state['providers'], true)) {
                continue;
            }
            $unit = $row['unit'] ?? 'site';
            $disposition = $units[$unit] ?? 'managed';
            $context = "[unit {$unit}; disposition {$disposition}] ";
            if ($disposition === 'seeded') {
                $id = 'SITE016_SEEDED_REGISTRATION_MISSING';
                $findings[] = new SiteDoctorFinding($id, FindingSeverity::Warning, 'composer.json', 1, $context . "Seeded provider registration {$row['fqcn']} is absent.", 'Review the developer-owned removal; regeneration does not restore seeded registrations.', hash('sha256', $id . "\0" . $row['fqcn'] . "\0" . $unit));
            } else {
                $findings[] = $this->finding('SITE015_REGISTRATION_DRIFT', 'composer.json', 1, $context . "Managed provider registration {$row['fqcn']} is absent.", 'Restore the recorded provider registration through the owning generation plan.', $row['fqcn'] . "\0" . $unit);
            }
        }

        return $findings;
    }

    private function readRequired(string $absolute, string $portable): string
    {
        $bytes = is_file($absolute) ? file_get_contents($absolute) : false;
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException("Required site contract file is missing or unreadable: {$portable}");
        }

        return $bytes;
    }

    private function finding(string $id, string $path, int $line, string $message, string $remediation, string $evidence): SiteDoctorFinding
    {
        return new SiteDoctorFinding($id, FindingSeverity::Error, $path, $line, $message, $remediation, hash('sha256', $id . "\0" . $path . "\0" . $line . "\0" . $evidence));
    }
}
