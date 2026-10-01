<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

use Waaseyaa\SiteContract\Capability\CapabilityState;
use Waaseyaa\SiteContract\Doctor\FindingSeverity;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;
use Waaseyaa\SiteContract\SiteManifest;

/** Read-only comparison of declarations with actual product adapter evidence. @api */
final class ManagementConformance
{
    /** @return list<SiteDoctorFinding> */
    public function inspect(
        ManagementManifest $manifest,
        SiteManifest $site,
        string $sourceDigest,
        ?ManagementInventoryInterface $inventory = null,
    ): array {
        $findings = [];
        if ($manifest->applicationId !== $site->application->id) {
            $findings[] = $this->finding(
                'SITE033_MANAGEMENT_APPLICATION_MISMATCH',
                $manifest,
                'application',
                'Management contract belongs to another application.',
            );
        }
        foreach ($manifest->operations as $id => $row) {
            if ($row['state'] === 'supported' && ($site->capabilities[$row['contract']->capabilityId]->state ?? null) !== CapabilityState::Active) {
                $findings[] = $this->finding(
                    'SITE034_MANAGEMENT_CAPABILITY_INACTIVE',
                    $manifest,
                    $id,
                    'Supported operation requires an active capability in site.yaml.',
                );
            }
        }
        if ($inventory === null) {
            $findings[] = $this->finding(
                'SITE035_MANAGEMENT_INVENTORY_UNVERIFIED',
                $manifest,
                'inventory',
                'No actual adapter inventory or executed verification results were supplied.',
            );

            return $findings;
        }
        try {
            if (preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1 || !hash_equals($sourceDigest, $inventory->sourceDigest())
                || !hash_equals($site->digest, $inventory->siteManifestDigest())) {
                $findings[] = $this->finding(
                    'SITE036_MANAGEMENT_SOURCE_DRIFT',
                    $manifest,
                    'inventory',
                    'Adapter inventory does not describe the current project source and site activation identities.',
                );

                return $findings;
            }
            $registered = [];
            foreach ($inventory->operations() as $row) {
                $operation = $this->validateOperation($row);
                if (isset($registered[$operation->id])) {
                    throw new \InvalidArgumentException('Invalid inventory.');
                }
                $normalized = new ManagementManifestParser()->operation($operation->toArray());
                $registered[$normalized->id] = $normalized;
            }
            $results = [];
            foreach ($inventory->verificationResults() as $row) {
                $result = $this->validateResult($row);
                if (isset($results[$result->operationId][$result->verificationId])) {
                    throw new \InvalidArgumentException('Invalid verification inventory.');
                }
                $results[$result->operationId][$result->verificationId] = $result;
            }
        } catch (\Throwable) {
            $findings[] = $this->finding(
                'SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE',
                $manifest,
                'inventory',
                'Actual adapter inventory could not be validated or read.',
            );

            return $findings;
        }
        foreach ($registered as $id => $operation) {
            if (($manifest->operations[$id]['state'] ?? null) !== 'supported') {
                $findings[] = $this->finding(
                    'SITE038_UNDECLARED_MANAGEMENT_OPERATION',
                    $manifest,
                    $id,
                    'Registered operation is undeclared, planned or explicitly unsupported.',
                );
            }
        }
        foreach ($manifest->operations as $id => $row) {
            if ($row['state'] !== 'supported') {
                continue;
            }
            $declared = $row['contract'];
            if (!isset($registered[$id])) {
                $findings[] = $this->finding(
                    'SITE039_MANAGEMENT_OPERATION_MISSING',
                    $manifest,
                    $id,
                    'Supported operation is absent from the actual adapter inventory.',
                );
                continue;
            }
            if (!hash_equals($declared->digest(), $registered[$id]->digest())) {
                $findings[] = $this->finding(
                    'SITE040_MANAGEMENT_CONTRACT_DRIFT',
                    $manifest,
                    $id,
                    'Registered binding, schemas, permissions or execution semantics differ from the declaration.',
                );
                continue;
            }
            foreach ($declared->metadata['verification'] as $verificationId) {
                $result = $results[$id][$verificationId] ?? null;
                if ($result === null || !$result->passed || !hash_equals($declared->digest(), $result->operationDigest)
                    || !hash_equals($sourceDigest, $result->sourceDigest)
                    || !hash_equals($site->digest, $result->siteManifestDigest)) {
                    $findings[] = $this->finding(
                        'SITE041_MANAGEMENT_VERIFICATION_UNPROVEN',
                        $manifest,
                        $id . ':' . $verificationId,
                        'Required acceptance check is missing, failed or describes a different operation contract.',
                    );
                }
            }
        }

        return $findings;
    }

    // Product adapters are a runtime boundary; PHPDoc does not validate their rows.
    private function validateOperation(mixed $row): ManagementOperation
    {
        if (!$row instanceof ManagementOperation) {
            throw new \InvalidArgumentException('Invalid inventory.');
        }

        return $row;
    }

    private function validateResult(mixed $row): ManagementVerificationResult
    {
        if (!$row instanceof ManagementVerificationResult) {
            throw new \InvalidArgumentException('Invalid verification inventory.');
        }

        return $row;
    }

    private function finding(string $code, ManagementManifest $manifest, string $subject, string $message): SiteDoctorFinding
    {
        return new SiteDoctorFinding(
            $code,
            FindingSeverity::Error,
            '.waaseyaa/management.json',
            1,
            $message . ' Subject: ' . $subject,
            'Reconcile the product-owned operation declaration, actual registration and current executed acceptance evidence; do not bypass grants.',
            hash('sha256', $manifest->digest . "\0" . $code . "\0" . $subject),
        );
    }
}
