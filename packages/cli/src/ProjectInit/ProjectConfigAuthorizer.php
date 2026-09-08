<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestSigningResult;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecision;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Generation\GeneratorFeatureNegotiation;
use Waaseyaa\SiteContract\SiteManifest;

/** Produces a signed bundle from the exact pure blueprint plan before consumer initialization. @api */
final readonly class ProjectConfigAuthorizer
{
    /** @var \Closure(string, string, int, array<string, string>): ConfigManifestSigningResult */
    private \Closure $signBundle;

    /** @param callable(string, string, int, array<string, string>): ConfigManifestSigningResult $signBundle */
    public function __construct(callable $signBundle)
    {
        $this->signBundle = $signBundle(...);
    }

    public function authorize(SiteManifest $manifest, BlueprintDecisionReceipt $decisionReceipt): ProjectConfigAuthorization
    {
        if ($manifest->applicationBlueprint === null) {
            throw new \InvalidArgumentException('Project configuration authorization requires an application blueprint.');
        }
        if ($decisionReceipt->decision !== BlueprintDecision::Approved || !$decisionReceipt->matches($manifest)) {
            throw new \InvalidArgumentException('Project configuration authorization requires approval of the exact blueprint and site manifest.');
        }

        GeneratorFeatureNegotiation::assert(
            $manifest,
            SiteArtifactRendererFactory::advertisedGeneratorFeatures(),
            'project:config:authorize',
        );
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        if (!hash_equals($manifest->digest, $plan->inputDigest)) {
            throw new \LogicException('The canonical blueprint plan is not bound to its parsed site manifest.');
        }

        $syncArtifacts = [];
        foreach ($plan->artifacts as $artifact) {
            if (str_starts_with($artifact->path, 'config/sync/')) {
                $syncArtifacts[] = $artifact;
            }
        }
        if ($syncArtifacts === []) {
            throw new \InvalidArgumentException('The application blueprint does not generate a configuration sync bundle.');
        }

        $temporaryRoot = tempnam(sys_get_temp_dir(), 'waaseyaa-project-config-');
        if (!\is_string($temporaryRoot)) {
            throw new \RuntimeException('Could not reserve a private project configuration authoring directory.');
        }
        if (!unlink($temporaryRoot) || !mkdir($temporaryRoot, 0o700)) {
            throw new \RuntimeException('Could not create a private project configuration authoring directory.');
        }
        $syncPath = $temporaryRoot . '/config/sync';

        try {
            foreach ($syncArtifacts as $artifact) {
                $relative = substr($artifact->path, strlen('config/sync/'));
                $path = $syncPath . '/' . $relative;
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('Could not stage generated configuration artifact %s.', $artifact->path));
                }
                if (file_put_contents($path, $artifact->content) !== strlen($artifact->content)) {
                    throw new \RuntimeException(sprintf('Could not stage generated configuration artifact %s.', $artifact->path));
                }
                chmod($path, 0o600);
            }

            ($this->signBundle)(
                $syncPath,
                ProjectConfigAuthorization::scope($manifest->digest, $plan->digest),
                1,
                ProjectConfigAuthorization::producerEvidence($manifest->digest, $plan->digest),
            );
            $envelope = ConfigManifestEnvelopeFile::read($syncPath)
                ?? throw new \RuntimeException('The configured signing authority did not produce a CFG-03 envelope.');

            return ProjectConfigAuthorization::issue($manifest->digest, $plan->digest, $envelope);
        } finally {
            self::removeTemporaryTree($temporaryRoot);
        }
    }

    private static function removeTemporaryTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            if (file_exists($root) || is_link($root)) {
                unlink($root);
            }

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                unlink($entry->getPathname());
            } else {
                rmdir($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
