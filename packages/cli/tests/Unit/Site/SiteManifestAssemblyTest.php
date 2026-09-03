<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Recipe\GovernedAuthoringRecipe;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;
use Waaseyaa\CLI\Site\SiteManifestAssembly;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * `SiteManifestAssembly` exists so the interactive wizard and a `--preset`
 * run cannot grow two different ideas of what a resolved decision means
 * (#2442). These tests assert the decisions the assembly encodes — an
 * activated capability against a declined one, what a declined one still
 * records, which recipes a decision selects — and finally that the fragments
 * compose into a document the closed `waaseyaa.site` schema accepts. That the
 * two callers publish the same site for equivalent decisions is asserted end
 * to end in `SiteInitHandlerTest`.
 */
#[CoversClass(SiteManifestAssembly::class)]
final class SiteManifestAssemblyTest extends TestCase
{
    /**
     * The published-content capability is the one capability whose public
     * surface is authored: every declared content type contributes its
     * canonical route, in the order it was declared.
     */
    #[Test]
    public function publishedContentPublishesEveryDeclaredContentTypeRouteInOrder(): void
    {
        $capability = SiteManifestAssembly::publishedContentCapability([
            ['id' => 'page', 'canonical_route' => '/{slug}'],
            ['id' => 'news_item', 'canonical_route' => '/news-item/{slug}'],
        ]);

        self::assertSame('published_content', $capability['id']);
        self::assertSame('active', $capability['state']);
        self::assertSame(['/{slug}', '/news-item/{slug}'], $capability['public_routes']);
        self::assertSame('public', $capability['data_classification']);
        self::assertSame('.waaseyaa/site.yaml#/capabilities/published_content', $capability['configuration_authority']);
    }

    /**
     * A declined capability is a recorded decision, not an omission: it keeps
     * its id and states why it is absent, and it carries none of the active
     * shape's provider/route/lifecycle claims. An activated one names the
     * package that owns it and claims no public routes of its own — governed
     * authoring is an editor surface, not a public one.
     */
    #[Test]
    public function decliningGovernedAuthoringRecordsTheDecisionInsteadOfAnActiveCapability(): void
    {
        $declined = SiteManifestAssembly::governedAuthoringCapability(false);

        self::assertSame(['id', 'state', 'reason'], array_keys($declined));
        self::assertSame('governed_authoring', $declined['id']);
        self::assertSame('not_needed', $declined['state']);

        $active = SiteManifestAssembly::governedAuthoringCapability(true);

        self::assertSame('active', $active['state']);
        self::assertSame('waaseyaa/page-builder', $active['package']);
        self::assertSame([], $active['public_routes']);
        self::assertSame('public', $active['data_classification']);
        self::assertArrayNotHasKey('reason', $active);
    }

    /**
     * A subscription is the only decision that introduces personal data, so
     * the retention period an operator authored has to reach both halves of
     * the record: the capability that classifies the data and the store that
     * carries the retention, export, and deletion operations.
     */
    #[Test]
    public function anActivatedSubscriptionCarriesTheAuthoredRetentionIntoThePersonalDataStore(): void
    {
        $capability = SiteManifestAssembly::subscriptionCapability('P30D');

        self::assertSame('active', $capability['state']);
        self::assertSame('personal', $capability['data_classification']);
        self::assertSame(['/subscribe', '/unsubscribe'], $capability['public_routes']);

        $store = SiteManifestAssembly::subscriberPersonalDataStore('P30D');

        self::assertSame('subscriber', $store['id']);
        self::assertSame('personal', $store['classification']);
        self::assertSame('P30D', $store['retention']);
        self::assertSame('subscriber:consent', $store['consent_operation']);
        self::assertSame('subscriber:export', $store['export_operation']);
        self::assertSame('subscriber:delete', $store['deletion_operation']);
    }

    #[Test]
    public function decliningASubscriptionRecordsTheDecisionAndClaimsNoPersonalRoutes(): void
    {
        $declined = SiteManifestAssembly::subscriptionCapability(null);

        self::assertSame(['id', 'state', 'reason'], array_keys($declined));
        self::assertSame('subscription', $declined['id']);
        self::assertSame('not_needed', $declined['state']);
        self::assertArrayNotHasKey('public_routes', $declined);
        self::assertArrayNotHasKey('data_classification', $declined);
    }

    /**
     * Recipes are what actually generate files, so a recipe is declared only
     * for a capability the operator activated. `published_content` is the one
     * decision every site makes.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('recipeSelectionProvider')]
    public function recipesAreDeclaredOnlyForActivatedCapabilities(bool $governedAuthoring, bool $subscription, array $expected): void
    {
        $recipes = SiteManifestAssembly::recipes($governedAuthoring, $subscription);

        self::assertSame($expected, array_column($recipes, 'id'));
        foreach ($recipes as $recipe) {
            self::assertSame($recipe['id'], $recipe['capability']);
        }
    }

    /** @return iterable<string, array{bool, bool, list<string>}> */
    public static function recipeSelectionProvider(): iterable
    {
        yield 'published content only' => [false, false, ['published_content']];
        yield 'governed authoring' => [true, false, ['published_content', 'governed_authoring']];
        yield 'subscription' => [false, true, ['published_content', 'subscription']];
        yield 'both' => [true, true, ['published_content', 'governed_authoring', 'subscription']];
    }

    /**
     * Each recipe renderer refuses a selection whose version or digest does
     * not match the installed first-party recipe, so the assembly has to bind
     * the installed ones rather than a copied literal.
     */
    #[Test]
    public function everyDeclaredRecipeBindsTheInstalledFirstPartyVersionAndDigest(): void
    {
        $recipes = SiteManifestAssembly::recipes(true, true);

        self::assertSame(PublishedContentRecipe::VERSION, $recipes[0]['version']);
        self::assertSame(PublishedContentRecipe::digest(), $recipes[0]['artifact_digest']);
        self::assertSame(GovernedAuthoringRecipe::VERSION, $recipes[1]['version']);
        self::assertSame(GovernedAuthoringRecipe::digest(), $recipes[1]['artifact_digest']);
        self::assertSame(SubscriptionRecipe::VERSION, $recipes[2]['version']);
        self::assertSame(SubscriptionRecipe::digest(), $recipes[2]['artifact_digest']);
    }

    /**
     * The assembled document is an input to `SiteManifestParser`, so every
     * fragment above has to compose into a document that closed schema
     * accepts — including its cross-section rule that a recipe may only
     * reference an active capability (SITE031). Parsing it back is how the
     * assembly is held to that, and how each resolved decision is shown to
     * survive into the manifest an operator reviews.
     */
    #[Test]
    public function theAssembledDocumentParsesAsAManifestPreservingEveryResolvedDecision(): void
    {
        $contentTypes = [
            ['id' => 'page', 'canonical_route' => '/{slug}'],
            ['id' => 'article', 'canonical_route' => '/article/{slug}'],
        ];
        $lockSha256 = str_repeat('a', 64);

        $yaml = SiteManifestAssembly::document(
            'example-nation',
            'Example Nation',
            'APP_ORIGIN',
            $contentTypes,
            [
                SiteManifestAssembly::publishedContentCapability($contentTypes),
                SiteManifestAssembly::governedAuthoringCapability(true),
                SiteManifestAssembly::subscriptionCapability('P2Y'),
            ],
            [SiteManifestAssembly::subscriberPersonalDataStore('P2Y')],
            SiteManifestAssembly::recipes(true, true),
            $lockSha256,
        );

        $manifest = new SiteManifestParser()->parse($yaml, '<assembly>');

        self::assertSame(1, $manifest->schemaVersion);
        self::assertSame(1, $manifest->generatorVersion);
        self::assertSame('example-nation', $manifest->application->id);
        self::assertSame('Example Nation', $manifest->application->name);
        self::assertSame('APP_ORIGIN', $manifest->application->canonicalOriginConfigKey);
        self::assertSame('exact-lock', $manifest->framework->revisionPolicy);
        self::assertSame($lockSha256, $manifest->framework->observedLockSha256);
        self::assertSame('/{slug}', $manifest->contentTypes['page']->canonicalRoute);
        self::assertSame('/article/{slug}', $manifest->contentTypes['article']->canonicalRoute);
        self::assertSame('active', $manifest->capabilities['published_content']->state->value);
        self::assertSame('active', $manifest->capabilities['governed_authoring']->state->value);
        self::assertSame('active', $manifest->capabilities['subscription']->state->value);
        self::assertSame('P2Y', $manifest->personalDataStores['subscriber']->retention);
        self::assertSame(
            ['governed_authoring', 'published_content', 'subscription'],
            array_keys($manifest->recipes),
        );
        self::assertSame('bin/maintenance/site-verify', $manifest->verificationCommand);
    }
}
