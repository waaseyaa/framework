<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;

/** @api */
final readonly class GeneratedSite
{
    /** @var array<string, GeneratedArtifact> */
    public array $artifacts;

    /** @param iterable<GeneratedArtifact> $artifacts */
    public function __construct(
        public int $generatorVersion,
        public string $manifestDigest,
        iterable $artifacts,
    ) {
        if ($generatorVersion < 1 || preg_match('/^[a-f0-9]{64}$/D', $manifestDigest) !== 1) {
            throw new \InvalidArgumentException('Generated site identity is invalid.');
        }
        $indexed = [];
        foreach ($artifacts as $artifact) {
            if (isset($indexed[$artifact->path])) {
                throw new \InvalidArgumentException("Duplicate generated artifact: {$artifact->path}");
            }
            $indexed[$artifact->path] = $artifact;
        }
        ksort($indexed, SORT_STRING);
        $metadata = $indexed['.waaseyaa/generated.json'] ?? null;
        if (!$metadata instanceof GeneratedArtifact) {
            throw new \InvalidArgumentException('Generated site ownership metadata is required.');
        }
        $rows = [];
        foreach ($indexed as $path => $artifact) {
            if ($path === '.waaseyaa/generated.json') {
                continue;
            }
            $row = [
                'path' => $path,
                'mode' => sprintf('%04o', $artifact->mode),
                'managed_sha256' => $artifact->managedDigest(),
            ];
            if ($artifact->extensionRegion !== null) {
                $row['extension_region'] = $artifact->extensionRegion;
            }
            $rows[] = $row;
        }
        $expectedMetadata = CanonicalJson::encode([
            'schema' => 'waaseyaa.generated',
            'version' => 1,
            'generator_version' => $generatorVersion,
            'manifest_digest' => $manifestDigest,
            'artifacts' => $rows,
        ]) . "\n";
        if (!hash_equals($expectedMetadata, $metadata->content)) {
            throw new \InvalidArgumentException('Generated site ownership metadata does not match its artifacts.');
        }
        $this->artifacts = $indexed;
    }

    /** @return array<string, string> */
    public function contents(): array
    {
        return array_map(static fn(GeneratedArtifact $artifact): string => $artifact->content, $this->artifacts);
    }
}
