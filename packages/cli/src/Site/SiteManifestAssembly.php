<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Symfony\Component\Yaml\Yaml;
use Waaseyaa\CLI\Site\Recipe\GovernedAuthoringRecipe;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;

/**
 * The one place that turns resolved product decisions (content types,
 * whether governed authoring is active, whether a subscription store is
 * needed) into `waaseyaa.site` capability/recipe fragments and the final
 * manifest document. `SiteManifestWizard` (interactive, every decision
 * asked) and `SitePresetResolver` (interactive or non-interactive, the
 * governed-authoring/personal-data decisions fixed by the chosen preset)
 * both call this so the two paths emit byte-identical capability/recipe
 * shapes for the same resolved decision, never their own copy that could
 * drift.
 */
final class SiteManifestAssembly
{
    /**
     * @param list<array{id: string, canonical_route: string}> $contentTypes
     * @return array<string, mixed>
     */
    public static function publishedContentCapability(array $contentTypes): array
    {
        return [
            'id' => 'published_content',
            'state' => 'active',
            'package' => 'waaseyaa/listing',
            'provider' => 'site.published_content',
            'configuration_authority' => '.waaseyaa/site.yaml#/capabilities/published_content',
            'public_routes' => array_column($contentTypes, 'canonical_route'),
            'data_classification' => 'public',
            'lifecycle' => ['create', 'revise', 'publish', 'unpublish', 'archive'],
            'verification' => ['tests/Acceptance/SiteGoldenPathTest.php'],
        ];
    }

    /** @return array<string, mixed> */
    public static function governedAuthoringCapability(bool $active): array
    {
        return $active
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
    }

    /** @return array<string, mixed> */
    public static function subscriptionCapability(?string $retention): array
    {
        return $retention !== null
            ? [
                'id' => 'subscription',
                'state' => 'active',
                'package' => 'waaseyaa/database-legacy',
                'provider' => 'site.subscription',
                'configuration_authority' => '.waaseyaa/site.yaml#/capabilities/subscription',
                'public_routes' => ['/subscribe', '/unsubscribe'],
                'data_classification' => 'personal',
                'lifecycle' => ['consent', 'export', 'unsubscribe', 'delete', 'retain'],
                'verification' => ['tests/Acceptance/SubscriptionRecipeTest.php'],
            ]
            : ['id' => 'subscription', 'state' => 'not_needed', 'reason' => 'No personal-information subscription is required.'];
    }

    /** @return array<string, mixed> */
    public static function subscriberPersonalDataStore(string $retention): array
    {
        return [
            'id' => 'subscriber',
            'classification' => 'personal',
            'consent_operation' => 'subscriber:consent',
            'retention' => $retention,
            'export_operation' => 'subscriber:export',
            'deletion_operation' => 'subscriber:delete',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function recipes(bool $governedAuthoring, bool $subscription): array
    {
        $recipes = [[
            'id' => 'published_content',
            'version' => PublishedContentRecipe::VERSION,
            'capability' => 'published_content',
            'artifact_digest' => PublishedContentRecipe::digest(),
        ]];
        if ($governedAuthoring) {
            $recipes[] = [
                'id' => 'governed_authoring',
                'version' => GovernedAuthoringRecipe::VERSION,
                'capability' => 'governed_authoring',
                'artifact_digest' => GovernedAuthoringRecipe::digest(),
            ];
        }
        if ($subscription) {
            $recipes[] = [
                'id' => 'subscription',
                'version' => SubscriptionRecipe::VERSION,
                'capability' => 'subscription',
                'artifact_digest' => SubscriptionRecipe::digest(),
            ];
        }

        return $recipes;
    }

    /**
     * @param list<array{id: string, canonical_route: string}> $contentTypes
     * @param list<array<string, mixed>> $capabilities
     * @param list<array<string, mixed>> $personalDataStores
     * @param list<array<string, mixed>> $recipes
     */
    public static function document(
        string $id,
        string $name,
        string $originKey,
        array $contentTypes,
        array $capabilities,
        array $personalDataStores,
        array $recipes,
        string $lockSha256,
    ): string {
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
                'observed_lock_sha256' => $lockSha256,
            ],
            'content_types' => $contentTypes,
            'capabilities' => $capabilities,
            'personal_data_stores' => $personalDataStores,
            'recipes' => $recipes,
            'verification' => ['command' => 'bin/maintenance/site-verify'],
        ], 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
