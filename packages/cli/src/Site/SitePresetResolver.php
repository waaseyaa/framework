<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\SiteContract\ContentTypeDeclaration;
use Waaseyaa\SiteContract\Seed\SiteSeedParser;

/**
 * Resolves a `site:init --preset` choice (#2442) to a complete `waaseyaa.site`
 * answer document, once, at initialization time.
 *
 * Per ADR-024 D-3/D-4 this class is the entire lifetime of the preset
 * choice: it asks (or reads) application identity and content types, then
 * hands `SiteManifestAssembly` the fixed decision that names the preset —
 * `governed_authoring` active only for `editorial` — to build the same
 * manifest shape `SiteManifestWizard` builds for an operator who typed the
 * equivalent answers by hand. The returned YAML is passed straight into the
 * existing `SiteManifestParser` → `SiteArtifactRendererFactory` →
 * `SiteInitializationService` pipeline; this class produces input to that
 * pipeline and owns no transaction, artifact, or ownership state of its own.
 * Neither preset selects the `subscription` capability — personal-data
 * collection remains a decision an operator makes explicitly, preset or not.
 *
 * @api
 */
final class SitePresetResolver
{
    public function resolveInteractively(SitePreset $preset, SymfonyCommandIO $io, string $projectRoot): string
    {
        $lockSha256 = hash_file('sha256', $this->lockPath($projectRoot));

        $defaultName = basename($projectRoot);
        $name = SiteManifestQuestions::required($io, 'What is the public name of this application?', $defaultName);
        $defaultId = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $id = SiteManifestQuestions::required($io, 'What stable application ID should identify it?', $defaultId);
        $originKey = SiteManifestQuestions::required($io, 'Which configuration key supplies the canonical production origin?', 'APP_ORIGIN');
        $contentIds = SiteManifestQuestions::ids(SiteManifestQuestions::required($io, 'Which public content types are required? Enter comma-separated IDs.', 'page'));
        $contentTypes = [];
        foreach ($contentIds as $index => $contentId) {
            $defaultRoute = $index === 0 ? '/{slug}' : '/' . str_replace('_', '-', $contentId) . '/{slug}';
            $contentTypes[] = [
                'id' => $contentId,
                'canonical_route' => SiteManifestQuestions::required($io, "What is the canonical route template for {$contentId}?", $defaultRoute),
            ];
        }

        return $this->build($preset, $id, $name, $originKey, $contentTypes, (string) $lockSha256);
    }

    public function resolveFromSeedDocument(SitePreset $preset, string $yaml, string $sourceLabel, string $projectRoot): string
    {
        $seed = new SiteSeedParser()->parse($yaml, $sourceLabel);
        $lockSha256 = hash_file('sha256', $this->lockPath($projectRoot));

        return $this->build(
            $preset,
            $seed->application->id,
            $seed->application->name,
            $seed->application->canonicalOriginConfigKey,
            array_values(array_map(
                static fn(ContentTypeDeclaration $contentType): array => $contentType->toArray(),
                $seed->contentTypes,
            )),
            (string) $lockSha256,
        );
    }

    private function lockPath(string $projectRoot): string
    {
        $lock = rtrim($projectRoot, '/\\') . '/composer.lock';
        if (!is_file($lock)) {
            throw new \RuntimeException('Site initialization requires a composer.lock so framework provenance can be bound.');
        }

        return $lock;
    }

    /** @param list<array{id: string, canonical_route: string}> $contentTypes */
    private function build(SitePreset $preset, string $id, string $name, string $originKey, array $contentTypes, string $lockSha256): string
    {
        $governedAuthoring = $preset === SitePreset::Editorial;
        $capabilities = [
            SiteManifestAssembly::publishedContentCapability($contentTypes),
            SiteManifestAssembly::governedAuthoringCapability($governedAuthoring),
            SiteManifestAssembly::subscriptionCapability(null),
        ];
        $recipes = SiteManifestAssembly::recipes($governedAuthoring, false);

        return SiteManifestAssembly::document($id, $name, $originKey, $contentTypes, $capabilities, [], $recipes, $lockSha256);
    }
}
