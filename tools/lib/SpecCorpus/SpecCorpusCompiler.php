<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Compiles a bounded manifest of docs/specs into a versioned agent corpus.
 *
 * @api
 */
final class SpecCorpusCompiler
{
    public const CORPUS_VERSION = '1';

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{manifest: array<string, mixed>, index: array<string, mixed>, documents: array<string, array<string, mixed>>}
     */
    public static function compile(string $repoRoot, array $manifest, ?string $frameworkVersion = null): array
    {
        $validated = SpecCorpusGuard::validateManifest($manifest);
        $frameworkVersion ??= self::readFrameworkVersion($repoRoot);
        $documents = [];
        $manifestDocuments = [];

        foreach ($validated['specs'] as $entry) {
            SpecCorpusGuard::assertDocumentId($entry['id']);
            $absolutePath = SpecCorpusGuard::resolveSpecPath($repoRoot, $entry['path']);

            $raw = file_get_contents($absolutePath);
            if ($raw === false) {
                throw new SpecCorpusException("Cannot read spec file: {$entry['path']}");
            }

            $frontmatter = SpecFrontmatter::parse($raw);
            $manifestMeta = SpecFrontmatter::fromManifestEntry($entry);
            $metadata = self::mergeMetadata($frontmatter, $manifestMeta, $entry['id']);
            $body = SpecFrontmatter::bodyWithoutFrontmatter($raw);
            $sanitized = SpecSanitizer::sanitize($body);
            $chunks = SpecChunker::chunk($entry['id'], $sanitized['retrieval_text']);
            $sourceDigest = SpecChunker::digest($raw);

            $document = [
                'id' => $entry['id'],
                'title' => $metadata['title'],
                'lifecycle' => $metadata['lifecycle']->value,
                'superseded_by' => $metadata['superseded_by'],
                'supersedes' => $metadata['supersedes'],
                'source' => [
                    'path' => self::relativePath($repoRoot, $absolutePath),
                    'digest' => $sourceDigest,
                ],
                'provenance' => $sanitized['provenance'],
                'retrieval_text' => $sanitized['retrieval_text'],
                'chunks' => $chunks,
            ];

            $documents[$entry['id']] = $document;
            $manifestDocuments[$entry['id']] = [
                'title' => $metadata['title'],
                'lifecycle' => $metadata['lifecycle']->value,
                'source_path' => $document['source']['path'],
                'source_digest' => $sourceDigest,
                'superseded_by' => $metadata['superseded_by'],
            ];
        }

        self::validateSupersession($documents);

        $corpusDigest = self::corpusDigest(
            $validated['corpus_version'],
            $frameworkVersion,
            $manifestDocuments,
        );

        $corpusManifest = [
            'corpus_version' => $validated['corpus_version'],
            'framework_version' => $frameworkVersion,
            'corpus_digest' => $corpusDigest,
            'documents' => $manifestDocuments,
        ];

        $indexEntries = [];
        foreach ($documents as $document) {
            if ($document['lifecycle'] !== SpecLifecycle::Live->value) {
                continue;
            }
            $indexEntries[] = [
                'id' => $document['id'],
                'title' => $document['title'],
                'source_path' => $document['source']['path'],
                'source_digest' => $document['source']['digest'],
            ];
        }
        usort($indexEntries, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        $index = [
            'corpus_version' => $validated['corpus_version'],
            'framework_version' => $frameworkVersion,
            'corpus_digest' => $corpusDigest,
            'entries' => $indexEntries,
        ];

        return [
            'manifest' => $corpusManifest,
            'index' => $index,
            'documents' => $documents,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     */
    private static function validateSupersession(array $documents): void
    {
        foreach ($documents as $document) {
            $lifecycle = SpecLifecycle::fromString((string) $document['lifecycle']);
            $supersededBy = $document['superseded_by'];

            if ($lifecycle === SpecLifecycle::Superseded) {
                if (!is_string($supersededBy) || $supersededBy === '') {
                    throw new SpecCorpusException(
                        "Document {$document['id']} is superseded but missing superseded_by.",
                    );
                }
                if (!isset($documents[$supersededBy])) {
                    throw new SpecCorpusException(
                        "Document {$document['id']} superseded_by target '{$supersededBy}' is not compiled.",
                    );
                }
                $targetLifecycle = SpecLifecycle::fromString((string) $documents[$supersededBy]['lifecycle']);
                if ($targetLifecycle !== SpecLifecycle::Live) {
                    throw new SpecCorpusException(
                        "Document {$document['id']} superseded_by target '{$supersededBy}' must be live.",
                    );
                }
            } elseif ($supersededBy !== null && $supersededBy !== '') {
                throw new SpecCorpusException(
                    "Document {$document['id']} declares superseded_by but lifecycle is not superseded.",
                );
            }
        }
    }

    /**
     * @param array{
     *   lifecycle: SpecLifecycle,
     *   superseded_by: ?string,
     *   supersedes: ?string,
     *   declared_title: ?string,
     *   derived_title: ?string,
     *   declared: bool
     * } $frontmatter
     * @param array{lifecycle: SpecLifecycle, superseded_by: ?string, supersedes: ?string, title: ?string} $manifestMeta
     *
     * @return array{lifecycle: SpecLifecycle, superseded_by: ?string, supersedes: ?string, title: ?string}
     */
    private static function mergeMetadata(array $frontmatter, array $manifestMeta, string $id): array
    {
        if ($frontmatter['declared']) {
            self::assertNoConflict($id, 'lifecycle', $frontmatter['lifecycle']->value, $manifestMeta['lifecycle']->value);
            self::assertOptionalConflict($id, 'superseded_by', $frontmatter['superseded_by'], $manifestMeta['superseded_by']);
            self::assertOptionalConflict($id, 'supersedes', $frontmatter['supersedes'], $manifestMeta['supersedes']);
            self::assertOptionalConflict($id, 'title', $frontmatter['declared_title'], $manifestMeta['title']);

            $lifecycle = $frontmatter['lifecycle'];
            $supersededBy = $frontmatter['superseded_by'] ?? $manifestMeta['superseded_by'];
            $supersedes = $frontmatter['supersedes'] ?? $manifestMeta['supersedes'];
            $title = self::resolveTitle($manifestMeta['title'], $frontmatter['declared_title'], $frontmatter['derived_title']);
        } else {
            $lifecycle = $manifestMeta['lifecycle'];
            $supersededBy = $manifestMeta['superseded_by'];
            $supersedes = $manifestMeta['supersedes'];
            $title = self::resolveTitle($manifestMeta['title'], null, $frontmatter['derived_title']);
        }

        if ($lifecycle === SpecLifecycle::Superseded && ($supersededBy === null || $supersededBy === '')) {
            throw new SpecCorpusException("Document {$id} is superseded but missing superseded_by.");
        }

        return [
            'lifecycle' => $lifecycle,
            'superseded_by' => $supersededBy,
            'supersedes' => $supersedes,
            'title' => $title,
        ];
    }

    private static function resolveTitle(?string $manifestTitle, ?string $declaredTitle, ?string $derivedTitle): ?string
    {
        return $manifestTitle ?? $declaredTitle ?? $derivedTitle;
    }

    private static function assertNoConflict(string $id, string $field, string $front, string $manifest): void
    {
        if ($front !== $manifest) {
            throw new SpecCorpusException(
                "Document {$id} has conflicting {$field} between frontmatter ('{$front}') and manifest ('{$manifest}').",
            );
        }
    }

    private static function assertOptionalConflict(string $id, string $field, ?string $front, ?string $manifest): void
    {
        if ($front !== null && $manifest !== null && $front !== $manifest) {
            throw new SpecCorpusException(
                "Document {$id} has conflicting {$field} between frontmatter ('{$front}') and manifest ('{$manifest}').",
            );
        }
    }

    /**
     * Metadata identity digest for manifest/index agreement. This does not seal
     * retrieval bodies or chunk text; #2662 must verify full content before trust.
     *
     * @param array<string, array{title: ?string, lifecycle: string, source_path: string, source_digest: string, superseded_by: ?string}> $manifestDocuments
     */
    public static function corpusDigest(string $corpusVersion, string $frameworkVersion, array $manifestDocuments): string
    {
        ksort($manifestDocuments);
        $identity = [
            'corpus_version' => $corpusVersion,
            'framework_version' => $frameworkVersion,
            'documents' => $manifestDocuments,
        ];
        $json = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return SpecChunker::digest($json);
    }

    public static function verifyCompiledDigest(array $compiled): void
    {
        $manifest = $compiled['manifest'];
        $expected = self::corpusDigest(
            (string) $manifest['corpus_version'],
            (string) $manifest['framework_version'],
            $manifest['documents'],
        );

        if ($expected !== $manifest['corpus_digest']) {
            throw new SpecCorpusException('Compiled corpus_digest does not match authoritative metadata inputs.');
        }

        if ($expected !== $compiled['index']['corpus_digest']) {
            throw new SpecCorpusException('Index corpus_digest does not match manifest.');
        }
    }

    public static function readFrameworkVersion(string $repoRoot): string
    {
        $versionFile = rtrim($repoRoot, '/') . '/VERSION';
        if (!is_readable($versionFile)) {
            throw new SpecCorpusException("VERSION file is not readable at {$versionFile}");
        }

        $version = trim((string) file_get_contents($versionFile));
        if ($version === '' || preg_match('/[\r\n\0]/', $version) === 1) {
            throw new SpecCorpusException('VERSION file is empty or malformed.');
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadManifest(string $manifestFile): array
    {
        if (!is_readable($manifestFile)) {
            throw new SpecCorpusException("Manifest is not readable: {$manifestFile}");
        }

        $raw = file_get_contents($manifestFile);
        if ($raw === false) {
            throw new SpecCorpusException("Cannot read manifest: {$manifestFile}");
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new SpecCorpusException('Manifest must be a JSON object.');
        }

        return SpecCorpusGuard::validateManifest($decoded);
    }

    /**
     * @param array{manifest: array<string, mixed>, index: array<string, mixed>, documents: array<string, array<string, mixed>>} $compiled
     */
    public static function writeOutput(string $outputDir, array $compiled): void
    {
        self::verifyCompiledDigest($compiled);

        $outputReal = SpecCorpusGuard::resolveOutputDirectory($outputDir);
        SpecCorpusGuard::assertOutputTargetAbsent($outputReal);

        $staging = dirname($outputReal) . '/.spec-corpus-staging-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();

        try {
            $filesystem->mkdir($staging . '/documents');

            ksort($compiled['documents']);
            foreach ($compiled['documents'] as $id => $document) {
                $path = SpecCorpusGuard::documentOutputPath($staging . '/documents', $id);
                self::writeJson($path, $document);
            }

            self::writeJson($staging . '/manifest.json', $compiled['manifest']);
            self::writeJson($staging . '/index.json', $compiled['index']);

            if (!rename($staging, $outputReal)) {
                throw new SpecCorpusException("Cannot publish corpus to {$outputReal}");
            }

            $staging = '';
        } finally {
            if ($staging !== '' && is_dir($staging)) {
                $filesystem->remove($staging);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) === false) {
            throw new SpecCorpusException("Cannot write temp file: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new SpecCorpusException("Cannot write file: {$path}");
        }
    }

    private static function relativePath(string $repoRoot, string $absolutePath): string
    {
        $repoReal = realpath($repoRoot);
        if ($repoReal === false) {
            throw new SpecCorpusException('Repository root is not resolvable.');
        }

        $prefix = $repoReal . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $prefix)) {
            return str_replace('\\', '/', substr($absolutePath, strlen($prefix)));
        }

        return str_replace('\\', '/', $absolutePath);
    }
}
