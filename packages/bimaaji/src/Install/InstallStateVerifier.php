<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Read-only verification that a client's installed files match the canonical
 * inventory the transformers would emit today (#2660).
 *
 * @api
 */
final class InstallStateVerifier
{
    /**
     * @return list<string> human-readable drift messages; empty when verified
     */
    public function verifyClient(
        string $projectRoot,
        ClientTransformerInterface $transformer,
        SkillInventory $inventory,
    ): array {
        $issues = [];
        $expected = $transformer->targetFiles($inventory->all());
        $manifest = InstalledManifest::load($projectRoot);
        $recorded = $manifest->targetsFor($transformer->clientId());

        foreach ($expected as $file) {
            $absolute = $projectRoot . DIRECTORY_SEPARATOR . $file->path;
            $isRecorded = array_key_exists($file->path, $recorded);

            if (!is_file($absolute)) {
                $issues[] = $isRecorded
                    ? sprintf('Missing owned target %s.', $file->path)
                    : sprintf('Missing expected target %s (not yet installed).', $file->path);
                continue;
            }

            $contents = @file_get_contents($absolute);
            if ($contents === false) {
                $issues[] = sprintf('Cannot read expected target %s.', $file->path);
                continue;
            }

            if (ManagedRegion::extract($contents) === null) {
                $issues[] = $isRecorded
                    ? sprintf('Owned target %s lacks a managed region.', $file->path)
                    : sprintf('Target %s is unmanaged (hand-authored file at path).', $file->path);
                continue;
            }

            $merged = ManagedRegion::splice($contents, $file->content);
            if ($merged === null) {
                $issues[] = sprintf('Cannot verify managed region of %s.', $file->path);
                continue;
            }

            if (sha1($contents) !== sha1($merged)) {
                $issues[] = sprintf('Drift in managed region of %s.', $file->path);
            }
        }

        foreach (array_diff_key($recorded, array_flip(array_map(static fn(TargetFile $f): string => $f->path, $expected))) as $stalePath => $_sha1) {
            $absolute = $projectRoot . DIRECTORY_SEPARATOR . $stalePath;
            if (is_file($absolute)) {
                $issues[] = sprintf('Stale owned target %s is still on disk.', $stalePath);
            }
        }

        return $issues;
    }

    /**
     * @param list<ClientTransformerInterface> $transformers
     * @return array<string, string> skill id => source sha256, must match across clients
     */
    public function canonicalSkillSourceHashes(SkillInventory $inventory): array
    {
        return $inventory->sourceSha256ById();
    }
}
