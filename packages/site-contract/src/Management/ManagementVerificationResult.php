<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

/** Executed product check tied to the exact operation contract. @api */
final readonly class ManagementVerificationResult
{
    public function __construct(
        public string $operationId,
        public string $verificationId,
        public string $operationDigest,
        public bool $passed,
        public string $evidenceReference,
        public string $sourceDigest,
        public string $siteManifestDigest,
    ) {
        if ($operationId === '' || $verificationId === '' || trim($evidenceReference) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $operationDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $siteManifestDigest) !== 1) {
            throw new \InvalidArgumentException('Management verification requires exact operation identity and a non-secret evidence reference.');
        }
    }
}
