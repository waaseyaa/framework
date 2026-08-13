<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Symfony\Component\Yaml\Yaml;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\SiteContract\Generation\PublishedContentRecipe;

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
        $name = $this->required($io, 'What is the public name of this application?', $defaultName);
        $defaultId = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $id = $this->required($io, 'What stable application ID should identify it?', $defaultId);
        $originKey = $this->required($io, 'Which configuration key supplies the canonical production origin?', 'APP_ORIGIN');
        $contentIds = $this->ids($this->required($io, 'Which public content types are required? Enter comma-separated IDs.', 'page'));
        $contentTypes = [];
        foreach ($contentIds as $index => $contentId) {
            $defaultRoute = $index === 0 ? '/{slug}' : '/' . str_replace('_', '-', $contentId) . '/{slug}';
            $contentTypes[] = [
                'id' => $contentId,
                'canonical_route' => $this->required($io, "What is the canonical route template for {$contentId}?", $defaultRoute),
            ];
        }

        $capabilities = [[
            'id' => 'published_content',
            'state' => 'active',
            'package' => 'waaseyaa/listing',
            'provider' => 'site.published_content',
            'configuration_authority' => '.waaseyaa/site.yaml#/capabilities/published_content',
            'public_routes' => array_column($contentTypes, 'canonical_route'),
            'data_classification' => 'public',
            'lifecycle' => ['create', 'revise', 'publish', 'unpublish', 'archive'],
            'verification' => ['tests/Acceptance/SiteGoldenPathTest.php'],
        ]];
        $capabilities[] = $io->confirm('Will editors visually compose governed page layouts?', true)
            ? [
                'id' => 'governed_authoring',
                'state' => 'active',
                'package' => 'waaseyaa/page-builder',
                'provider' => 'site.page_builder',
                'configuration_authority' => '.waaseyaa/site.yaml#/capabilities/governed_authoring',
                'public_routes' => [],
                'data_classification' => 'public',
                'lifecycle' => ['create', 'revise', 'preview', 'publish', 'restore'],
                'verification' => ['tests/Acceptance/SiteGoldenPathTest.php'],
            ]
            : ['id' => 'governed_authoring', 'state' => 'not_needed', 'reason' => 'This application does not require visual page composition.'];

        $personalDataStores = [];
        if ($io->confirm('Will this application collect personal information?', false)) {
            $capabilities[] = [
                'id' => 'subscription',
                'state' => 'planned',
                'note' => 'Generate and review the private subscription recipe before activation.',
            ];
            $personalDataStores[] = [
                'id' => 'subscriber',
                'classification' => 'personal',
                'consent_operation' => 'subscriber:consent',
                'retention' => $this->required($io, 'What retention period applies to subscriber data?', 'P2Y'),
                'export_operation' => 'subscriber:export',
                'deletion_operation' => 'subscriber:delete',
            ];
        } else {
            $capabilities[] = ['id' => 'subscription', 'state' => 'not_needed', 'reason' => 'No personal-information subscription is required.'];
        }

        return Yaml::dump([
            'schema' => 'waaseyaa.site',
            'version' => 1,
            'generator_version' => 1,
            'application' => [
                'id' => $id,
                'name' => $name,
                'canonical_origin' => ['config_key' => $originKey],
            ],
            'framework' => [
                'revision_policy' => 'exact-lock',
                'observed_lock_sha256' => hash_file('sha256', $lock),
            ],
            'content_types' => $contentTypes,
            'capabilities' => $capabilities,
            'personal_data_stores' => $personalDataStores,
            'recipes' => [[
                'id' => 'published_content',
                'version' => PublishedContentRecipe::VERSION,
                'capability' => 'published_content',
                'artifact_digest' => PublishedContentRecipe::digest(),
            ]],
            'verification' => ['command' => 'bin/maintenance/site-verify'],
        ], 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private function required(SymfonyCommandIO $io, string $question, string $default): string
    {
        $answer = trim((string) $io->ask($question, $default));
        if ($answer === '') {
            throw new \InvalidArgumentException("A decision is required: {$question}");
        }

        return $answer;
    }

    /** @return list<string> */
    private function ids(string $input): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $input)),
            static fn(string $id): bool => $id !== '',
        )));
        if ($ids === []) {
            throw new \InvalidArgumentException('At least one public content type is required.');
        }

        return $ids;
    }
}
