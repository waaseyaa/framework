<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Committed-state verification of the Admin dist acceptance manifest.
 *
 * This is the gate half of #2524 and needs no Node toolchain: it re-derives
 * everything the manifest claims from the committed bytes. It is additive to —
 * never a replacement for — the authoritative D6 freshness gate
 * (bin/check-admin-dist-fresh), which remains the source-vs-bundle staleness
 * check.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final class AdminDistAcceptanceVerifier
{
    /** @return list<string> human-readable problems; empty means accepted */
    public function verify(string $projectRoot): array
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            return [sprintf('project root %s does not exist.', $projectRoot)];
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $manifestPath = $root . '/' . AdminDistAcceptanceManifest::PATH;
        if (!is_file($manifestPath)) {
            return [sprintf(
                '%s is missing — rebuild and accept with bin/build-admin-dist.',
                AdminDistAcceptanceManifest::PATH,
            )];
        }

        try {
            $manifest = AdminDistAcceptanceManifest::fromJson((string) file_get_contents($manifestPath));
        } catch (AdminDistAcceptanceException $exception) {
            return [$exception->getMessage()];
        }
        $document = $manifest->document;
        $problems = [];

        if (($document['manifestVersion'] ?? null) !== AdminDistAcceptanceManifest::VERSION) {
            return [sprintf(
                '%s declares an unsupported manifestVersion; this checkout accepts version %d.',
                AdminDistAcceptanceManifest::PATH,
                AdminDistAcceptanceManifest::VERSION,
            )];
        }
        if ($manifest->identityDigest() !== $manifest->recomputedIdentityDigest()) {
            $problems[] = 'the manifest identity digest does not match its own content (hand-edited manifest).';
        }
        if (($document['release']['package'] ?? null) !== AdminDistAcceptance::RELEASE_PACKAGE) {
            $problems[] = 'the manifest does not name the waaseyaa/admin-surface release package.';
        }

        $publishedPath = $root . '/' . AdminDistAcceptance::PUBLISHED_PATH;
        if (!is_dir($publishedPath)) {
            $problems[] = AdminDistAcceptance::PUBLISHED_PATH . ' is missing.';

            return $problems;
        }
        try {
            $published = AdminDistTreeInventory::scan($publishedPath);
        } catch (AdminDistAcceptanceException $exception) {
            $problems[] = $exception->getMessage();

            return $problems;
        }
        if (($document['published']['treeDigest'] ?? null) !== $published->digest) {
            $problems[] = sprintf(
                'the committed published tree digest %s does not match the manifest (%s) — the bundle was changed outside bin/build-admin-dist.',
                $published->digest,
                is_string($document['published']['treeDigest'] ?? null) ? $document['published']['treeDigest'] : 'absent',
            );
        }
        if (($document['published']['fileCount'] ?? null) !== $published->fileCount
            || ($document['published']['byteCount'] ?? null) !== $published->byteCount) {
            $problems[] = 'the manifest published file/byte counts do not describe the committed tree.';
        }

        $signaturePath = $root . '/' . AdminDistAcceptance::SIGNATURE_PATH;
        $signature = is_file($signaturePath) ? trim((string) file_get_contents($signaturePath)) : '';
        if ($signature === '' || $signature !== ($document['source']['signature'] ?? null)) {
            $problems[] = 'the manifest source signature does not match the committed dist.signature.';
        }
        $buildIdSignature = $document['source']['buildIdSignature'] ?? null;
        $buildId = $document['source']['buildId'] ?? null;
        if (!is_string($buildIdSignature) || $buildId !== 'waaseyaa-' . substr($buildIdSignature, 0, 32)) {
            $problems[] = 'the manifest build id is not derived from its own build-id signature.';
        } else {
            $problems = [...$problems, ...$this->verifyBuildIdentity($publishedPath, $buildId)];
        }

        $problems = [...$problems, ...$this->verifyMarkers($root, $publishedPath, $document)];

        return $problems;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function verifyMarkers(string $root, string $publishedPath, array $document): array
    {
        try {
            $markers = AdminDistSourceMarkerPolicy::load($root);
        } catch (AdminDistAcceptanceException $exception) {
            return [$exception->getMessage()];
        }

        $problems = [];
        $unsatisfied = $markers->unsatisfied($publishedPath);
        if ($unsatisfied !== []) {
            $problems[] = sprintf(
                'requested source marker(s) absent from the served bundle: %s — rebuild with bin/build-admin-dist.',
                implode(', ', $unsatisfied),
            );
        }
        if (($document['markers']['digest'] ?? null) !== $markers->digest()) {
            $problems[] = sprintf(
                'the declared source markers (%s) changed without a re-accepted manifest.',
                AdminDistSourceMarkerPolicy::PATH,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function verifyBuildIdentity(string $publishedPath, string $buildId): array
    {
        $latest = $publishedPath . '/_nuxt/builds/latest.json';
        if (!is_file($latest)) {
            return [];
        }
        try {
            $document = json_decode((string) file_get_contents($latest), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['the committed Nuxt build manifest is not readable JSON.'];
        }
        if (!is_array($document) || ($document['id'] ?? null) !== $buildId) {
            return ['the committed Nuxt build identity does not match the manifest build id.'];
        }

        return [];
    }
}
