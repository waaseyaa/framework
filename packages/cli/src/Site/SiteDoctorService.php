<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

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
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Site doctor requires an existing project root.');
        }
        $manifestPath = $root . '/.waaseyaa/site.yaml';
        $manifestBytes = $this->readRequired($manifestPath, '.waaseyaa/site.yaml');
        $manifest = new SiteManifestParser()->parse($manifestBytes, '.waaseyaa/site.yaml');
        $snapshot = new ProjectSourceDiscovery()->discover($root);
        $findings = new ArchitectureScanner()->scan($snapshot->sources);
        $findings = [...$findings, ...$this->provenanceFindings($root, $manifest->framework->observedLockSha256), ...$this->generatedArtifactFindings($root, $manifest)];
        $suppressed = [];
        $suppressionPath = $root . '/.waaseyaa/site-doctor-suppressions.yaml';
        if (is_file($suppressionPath)) {
            $application = new DoctorSuppressionSet()->apply($this->readRequired($suppressionPath, '.waaseyaa/site-doctor-suppressions.yaml'), $findings, $snapshot->digest, $today ?? new \DateTimeImmutable('today'));
            $findings = $application->findings;
            $suppressed = $application->suppressed;
        }

        $lockDigest = is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : false;
        $metadataDigest = is_file($root . '/.waaseyaa/generated.json') ? hash_file('sha256', $root . '/.waaseyaa/generated.json') : false;

        return SiteDoctorReport::strict(
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
    private function generatedArtifactFindings(string $root, \Waaseyaa\SiteContract\SiteManifest $manifest): array
    {
        $metadataPath = $root . '/.waaseyaa/generated.json';
        if (!is_file($metadataPath)) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated ownership metadata is missing.', 'Run site:init to restore the governed artifact set.', 'missing')];
        }
        try {
            $metadataBytes = $this->readRequired($metadataPath, '.waaseyaa/generated.json');
            $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated ownership metadata is invalid.', 'Run site:init to restore the governed artifact set.', 'invalid')];
        }
        if (!is_array($metadata) || ($metadata['schema'] ?? null) !== 'waaseyaa.generated' || ($metadata['version'] ?? null) !== 1 || ($metadata['manifest_digest'] ?? null) !== $manifest->digest || !is_array($metadata['artifacts'] ?? null)) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated ownership metadata does not bind this manifest.', 'Run site:init to restore the governed artifact set.', $metadataBytes)];
        }
        $expectedMetadata = SiteArtifactRendererFactory::create()->render($manifest)->artifacts['.waaseyaa/generated.json']->content;
        if (!hash_equals($expectedMetadata, $metadataBytes)) {
            return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated ownership metadata was substituted or does not match the declared generator version.', 'Run the declared generator migration or restore this generator version through site:init.', $metadataBytes)];
        }
        $findings = [];
        $seen = [];
        foreach ($metadata['artifacts'] as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['mode'] ?? null) || !is_string($row['managed_sha256'] ?? null) || isset($seen[$row['path']])) {
                $encodedRow = json_encode($row);
                $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', '.waaseyaa/generated.json', 1, 'Generated artifact ownership row is malformed or duplicated.', 'Regenerate the governed artifacts.', $encodedRow !== false ? $encodedRow : 'invalid-row');
                continue;
            }
            $seen[$row['path']] = true;
            $path = $root . '/' . $row['path'];
            $content = is_file($path) ? file_get_contents($path) : false;
            $extension = is_string($row['extension_region'] ?? null) ? $row['extension_region'] : null;
            try {
                $actualDigest = is_string($content) ? new GeneratedArtifact($row['path'], $content, intval($row['mode'], 8), $extension)->managedDigest() : '';
            } catch (\Throwable) {
                $actualDigest = '';
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $row['managed_sha256']) !== 1 || !hash_equals($row['managed_sha256'], $actualDigest)) {
                $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', $row['path'], 1, 'Managed generated artifact bytes were removed or substituted.', 'Restore the generated artifact through site:init; place local changes only in declared extension regions.', $actualDigest);
            }
            $fileMode = is_file($path) && DIRECTORY_SEPARATOR === '/' ? fileperms($path) : null;
            if ($fileMode !== null && ($fileMode === false || sprintf('%04o', $fileMode & 0o777) !== $row['mode'])) {
                $findings[] = $this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', $row['path'], 1, 'Managed generated artifact mode was substituted.', 'Restore the declared mode through site:init.', is_int($fileMode) ? sprintf('%04o', $fileMode & 0o777) : 'unreadable');
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
