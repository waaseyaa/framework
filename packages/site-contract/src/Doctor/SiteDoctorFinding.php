<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

/** @api */
final readonly class SiteDoctorFinding
{
    public function __construct(
        public string $id,
        public FindingSeverity $severity,
        public string $path,
        public int $line,
        public string $message,
        public string $remediation,
        public string $evidenceDigest,
    ) {
        if (preg_match('/^SITE[0-9]{3}_[A-Z0-9_]+$/D', $id) !== 1) {
            throw new \InvalidArgumentException('A site-doctor finding requires a stable identifier.');
        }
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('A site-doctor finding requires a portable repository path.');
        }
        if ($line < 1) {
            throw new \InvalidArgumentException('A site-doctor finding requires a positive line number.');
        }
        if ($message === '' || $remediation === '') {
            throw new \InvalidArgumentException('A site-doctor finding requires message and remediation text.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $evidenceDigest) !== 1) {
            throw new \InvalidArgumentException('A site-doctor finding requires a lowercase SHA-256 evidence digest.');
        }
    }

    /** @return array{id:string,severity:string,path:string,line:int,message:string,remediation:string,evidence_sha256:string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity->value,
            'path' => $this->path,
            'line' => $this->line,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'evidence_sha256' => $this->evidenceDigest,
        ];
    }
}
