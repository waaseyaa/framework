<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Doctor;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** @api */
final class DoctorSuppressionSet
{
    /**
     * @param list<SiteDoctorFinding> $findings
     */
    public function apply(string $yaml, array $findings, string $sourceDigest, \DateTimeImmutable $today): SuppressionApplication
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1) {
            throw new \InvalidArgumentException('Suppression application requires the exact source SHA-256 identity.');
        }
        try {
            $document = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('Invalid site-doctor suppression YAML.', previous: $exception);
        }
        if (!is_array($document) || !$this->hasExactKeys($document, ['schema', 'version', 'suppressions'])) {
            throw new \InvalidArgumentException('Suppression document keys must exactly match the versioned schema.');
        }
        if (($document['schema'] ?? null) !== 'waaseyaa.site-doctor-suppressions' || ($document['version'] ?? null) !== 1 || !is_array($document['suppressions'])) {
            throw new \InvalidArgumentException('Unsupported site-doctor suppression document.');
        }

        $remaining = $findings;
        $suppressed = [];
        $policyFindings = [];
        $seen = [];
        foreach (array_values($document['suppressions']) as $index => $row) {
            $path = '.waaseyaa/site-doctor-suppressions.yaml';
            if (!is_array($row) || !$this->hasExactKeys($row, ['finding_id', 'path', 'line', 'evidence_sha256', 'source_sha256', 'reason', 'expires'])) {
                $policyFindings[] = $this->policyFinding('SITE900_INVALID_SUPPRESSION', $path, $index + 1, 'Suppression entries must use the exact versioned schema.');
                continue;
            }
            $identity = implode("\0", array_map('strval', [$row['finding_id'], $row['path'], $row['line'], $row['evidence_sha256'], $row['source_sha256']]));
            $expires = is_string($row['expires']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $row['expires']) : false;
            $valid = is_string($row['finding_id'])
                && preg_match('/^SITE1[0-9]{2}_[A-Z0-9_]+$/D', $row['finding_id']) === 1
                && is_string($row['path'])
                && is_int($row['line'])
                && is_string($row['evidence_sha256'])
                && is_string($row['source_sha256'])
                && is_string($row['reason'])
                && trim($row['reason']) !== ''
                && preg_match('/^[a-f0-9]{64}$/D', $row['evidence_sha256']) === 1
                && hash_equals($sourceDigest, $row['source_sha256'])
                && $expires instanceof \DateTimeImmutable
                && $expires >= $today->setTime(0, 0)
                && !isset($seen[$identity]);
            $seen[$identity] = true;
            if (!$valid) {
                $policyFindings[] = $this->policyFinding('SITE900_INVALID_SUPPRESSION', $path, $index + 1, 'Suppression is malformed, expired, duplicated, or bound to another source identity.');
                continue;
            }
            $match = null;
            foreach ($remaining as $findingIndex => $finding) {
                if ($finding->id === $row['finding_id'] && $finding->path === $row['path'] && $finding->line === $row['line'] && hash_equals($finding->evidenceDigest, $row['evidence_sha256'])) {
                    $match = $findingIndex;
                    break;
                }
            }
            if ($match === null) {
                $policyFindings[] = $this->policyFinding('SITE901_UNUSED_SUPPRESSION', $path, $index + 1, 'Suppression does not match a current exact finding.');
                continue;
            }
            $suppressed[] = $remaining[$match];
            unset($remaining[$match]);
        }

        $active = [];
        foreach ($remaining as $finding) {
            $active[] = $finding;
        }

        return new SuppressionApplication([...$active, ...$policyFindings], $suppressed);
    }

    private function policyFinding(string $id, string $path, int $line, string $message): SiteDoctorFinding
    {
        return new SiteDoctorFinding(
            $id,
            FindingSeverity::Error,
            $path,
            max(1, $line),
            $message,
            'Remove or replace the entry with a reviewed exact, justified, unexpired suppression.',
            hash('sha256', $id . "\0" . $path . "\0" . $line . "\0" . $message),
        );
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }
}
