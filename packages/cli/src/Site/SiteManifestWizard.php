<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/** @api */
final class SiteManifestWizard
{
    public function create(SymfonyCommandIO $io, string $projectRoot): string
    {
        $lock = $projectRoot . '/composer.lock';
        if (!is_file($lock)) {
            throw new \RuntimeException('Interactive initialization requires a composer.lock so framework provenance can be bound.');
        }
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

        $capabilities = [SiteManifestAssembly::publishedContentCapability($contentTypes)];
        $governedAuthoring = $io->confirm('Will editors visually compose governed page layouts?', true);
        $capabilities[] = SiteManifestAssembly::governedAuthoringCapability($governedAuthoring);

        $personalDataStores = [];
        $retention = null;
        if ($io->confirm('Will this application collect personal information?', false)) {
            $retention = SiteManifestQuestions::required($io, 'What retention period applies to subscriber data?', 'P2Y');
            $personalDataStores[] = SiteManifestAssembly::subscriberPersonalDataStore($retention);
        }
        $capabilities[] = SiteManifestAssembly::subscriptionCapability($retention);

        $recipes = SiteManifestAssembly::recipes($governedAuthoring, $retention !== null);

        return SiteManifestAssembly::document($id, $name, $originKey, $contentTypes, $capabilities, $personalDataStores, $recipes, hash_file('sha256', $lock));
    }
}
