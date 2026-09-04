<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

use Waaseyaa\SiteContract\CanonicalJson;

/** @api */
final readonly class SiteDoctorReport
{
    /**
     * @param list<SiteDoctorFinding> $findings
     * @param list<SiteDoctorFinding> $suppressed
     */
    private function __construct(
        public string $manifestDigest,
        public string $sourceDigest,
        public string $composerLockDigest,
        public string $generatedMetadataDigest,
        public array $findings,
        public array $suppressed,
        public bool $passed,
        public string $summary,
    ) {}

    /**
     * @param list<SiteDoctorFinding> $findings
     * @param list<SiteDoctorFinding> $suppressed
     */
    public static function strict(
        string $manifestDigest,
        string $sourceDigest,
        array $findings,
        array $suppressed = [],
        ?string $composerLockDigest = null,
        ?string $generatedMetadataDigest = null,
    ): self {
        $composerLockDigest ??= str_repeat('0', 64);
        $generatedMetadataDigest ??= str_repeat('0', 64);
        foreach ([$manifestDigest, $sourceDigest, $composerLockDigest, $generatedMetadataDigest] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \InvalidArgumentException('Strict site-doctor reports require exact SHA-256 identities.');
            }
        }
        usort($findings, static fn(SiteDoctorFinding $left, SiteDoctorFinding $right): int => [
            $left->path,
            $left->line,
            $left->id,
            $left->evidenceDigest,
        ] <=> [
            $right->path,
            $right->line,
            $right->id,
            $right->evidenceDigest,
        ]);
        usort($suppressed, static fn(SiteDoctorFinding $left, SiteDoctorFinding $right): int => [$left->path, $left->line, $left->id] <=> [$right->path, $right->line, $right->id]);
        $passed = $findings === [];

        return new self($manifestDigest, $sourceDigest, $composerLockDigest, $generatedMetadataDigest, $findings, $suppressed, $passed, $passed ? 'OK' : 'FAILED');
    }

    /**
     * Generation-unit report policy (ADR-025 D-2.7 and D-2.1a).
     *
     * Used by unit-aware site:doctor. The legacy strict factory remains
     * unchanged; no unrelated warning earns an exception.
     *
     * @internal
     * @param list<SiteDoctorFinding> $findings
     * @param list<SiteDoctorFinding> $suppressed
     */
    public static function generation(
        string $manifestDigest,
        string $sourceDigest,
        array $findings,
        array $suppressed = [],
        ?string $composerLockDigest = null,
        ?string $generatedMetadataDigest = null,
    ): self {
        $report = self::strict($manifestDigest, $sourceDigest, $findings, $suppressed, $composerLockDigest, $generatedMetadataDigest);
        $blocking = array_filter($report->findings, static fn(SiteDoctorFinding $finding): bool =>
            !in_array($finding->id, ['SITE013_SEEDED_ARTIFACT_MODIFIED', 'SITE016_SEEDED_REGISTRATION_MISSING'], true)
            || $finding->severity !== FindingSeverity::Warning);
        if ($blocking !== [] || $report->findings === []) {
            return $report;
        }

        return new self(
            $report->manifestDigest,
            $report->sourceDigest,
            $report->composerLockDigest,
            $report->generatedMetadataDigest,
            $report->findings,
            $report->suppressed,
            true,
            'PASSED_WITH_NOTICES',
        );
    }

    public function exitCode(): int
    {
        return $this->passed ? 0 : 1;
    }

    /** @return array{schema:string,version:int,mode:string,manifest_sha256:string,source_sha256:string,composer_lock_sha256:string,generated_metadata_sha256:string,passed:bool,summary:string,findings:list<array{id:string,severity:string,path:string,line:int,message:string,remediation:string,evidence_sha256:string}>,suppressed:list<array{id:string,severity:string,path:string,line:int,message:string,remediation:string,evidence_sha256:string}>} */
    public function toArray(): array
    {
        return [
            'schema' => 'waaseyaa.site-doctor',
            'version' => 1,
            'mode' => 'strict',
            'manifest_sha256' => $this->manifestDigest,
            'source_sha256' => $this->sourceDigest,
            'composer_lock_sha256' => $this->composerLockDigest,
            'generated_metadata_sha256' => $this->generatedMetadataDigest,
            'passed' => $this->passed,
            'summary' => $this->summary,
            'findings' => array_map(static fn(SiteDoctorFinding $finding): array => $finding->toArray(), $this->findings),
            'suppressed' => array_map(static fn(SiteDoctorFinding $finding): array => $finding->toArray(), $this->suppressed),
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
