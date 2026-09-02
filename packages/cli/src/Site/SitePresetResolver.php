<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Symfony\Component\Yaml\Yaml;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

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
        [$id, $name, $originKey, $contentTypes] = $this->parseSeedDocument($yaml, $sourceLabel);
        $lockSha256 = hash_file('sha256', $this->lockPath($projectRoot));

        return $this->build($preset, $id, $name, $originKey, $contentTypes, (string) $lockSha256);
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

    /**
     * A preset seed document is deliberately not a `waaseyaa.site` answer
     * document — it carries only the identity/content-type inputs a preset
     * cannot resolve on its own (`SiteManifestSchema` requires capabilities,
     * recipes, personal_data_stores, and verification, none of which a seed
     * document supplies). This performs only the light shape checks needed
     * to gather those inputs; the resolved manifest this produces still
     * passes through the one existing `SiteManifestParser` for full closed-
     * schema validation, so no second validation authority is introduced.
     *
     * @return array{0: string, 1: string, 2: string, 3: list<array{id: string, canonical_route: string}>}
     */
    private function parseSeedDocument(string $yaml, string $sourceLabel): array
    {
        $data = Yaml::parse($yaml);
        if (!is_array($data)) {
            throw new \InvalidArgumentException("Preset seed document must be a YAML mapping: {$sourceLabel}");
        }
        $application = $data['application'] ?? null;
        if (!is_array($application)) {
            throw new \InvalidArgumentException("Preset seed document requires an application mapping: {$sourceLabel}");
        }
        $id = $application['id'] ?? null;
        $name = $application['name'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($name) || $name === '') {
            throw new \InvalidArgumentException("Preset seed document requires application.id and application.name: {$sourceLabel}");
        }
        $canonicalOrigin = $application['canonical_origin'] ?? null;
        $originKey = is_array($canonicalOrigin) ? ($canonicalOrigin['config_key'] ?? null) : null;
        if (!is_string($originKey) || $originKey === '') {
            throw new \InvalidArgumentException("Preset seed document requires application.canonical_origin.config_key: {$sourceLabel}");
        }
        $contentTypes = $data['content_types'] ?? null;
        if (!is_array($contentTypes) || $contentTypes === []) {
            throw new \InvalidArgumentException("Preset seed document requires at least one content type: {$sourceLabel}");
        }
        $normalized = [];
        foreach ($contentTypes as $contentType) {
            if (!is_array($contentType)) {
                throw new \InvalidArgumentException("Each preset seed content type requires id and canonical_route: {$sourceLabel}");
            }
            $contentTypeId = $contentType['id'] ?? null;
            $canonicalRoute = $contentType['canonical_route'] ?? null;
            if (!is_string($contentTypeId) || $contentTypeId === '' || !is_string($canonicalRoute) || $canonicalRoute === '') {
                throw new \InvalidArgumentException("Each preset seed content type requires id and canonical_route: {$sourceLabel}");
            }
            $normalized[] = ['id' => $contentTypeId, 'canonical_route' => $canonicalRoute];
        }

        return [$id, $name, $originKey, $normalized];
    }
}
