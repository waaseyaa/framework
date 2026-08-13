<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

use Waaseyaa\SiteContract\CanonicalJson;

/** @api */
final readonly class SiteDoctorReport
{
    /** @param list<SiteDoctorFinding> $findings */
    private function __construct(
        public string $manifestDigest,
        public string $sourceDigest,
        public array $findings,
        public bool $passed,
        public string $summary,
    ) {}

    /** @param list<SiteDoctorFinding> $findings */
    public static function strict(string $manifestDigest, string $sourceDigest, array $findings): self
    {
        foreach ([$manifestDigest, $sourceDigest] as $digest) {
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
        $passed = $findings === [];

        return new self($manifestDigest, $sourceDigest, $findings, $passed, $passed ? 'OK' : 'FAILED');
    }

    public function exitCode(): int
    {
        return $this->passed ? 0 : 1;
    }

    /** @return array{schema:string,version:int,mode:string,manifest_sha256:string,source_sha256:string,passed:bool,summary:string,findings:list<array{id:string,severity:string,path:string,line:int,message:string,remediation:string,evidence_sha256:string}>} */
    public function toArray(): array
    {
        return [
            'schema' => 'waaseyaa.site-doctor',
            'version' => 1,
            'mode' => 'strict',
            'manifest_sha256' => $this->manifestDigest,
            'source_sha256' => $this->sourceDigest,
            'passed' => $this->passed,
            'summary' => $this->summary,
            'findings' => array_map(static fn(SiteDoctorFinding $finding): array => $finding->toArray(), $this->findings),
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
