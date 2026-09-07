<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Starter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

final class CommunityEventsStarterContractTest extends TestCase
{
    #[Test]
    public function thePackagedStarterHasItsOwnTruthfulDomainIdentity(): void
    {
        $manifest = $this->manifest();
        self::assertSame('community-events', $manifest->application->id);
        self::assertSame(['event', 'organizer', 'venue'], array_keys($manifest->contentTypes));
        self::assertNotNull($manifest->applicationBlueprint);
        self::assertSame(['event', 'organizer', 'venue'], array_keys($manifest->applicationBlueprint->entities));
        self::assertSame(['event_organizer', 'event_venue'], array_keys($manifest->applicationBlueprint->relationships));
        self::assertSame(['contributor', 'event_administrator', 'reviewer'], array_keys($manifest->applicationBlueprint->roles));
    }

    #[Test]
    public function roles_and_workflow_use_only_explicit_governed_authority(): void
    {
        $blueprint = $this->manifest()->applicationBlueprint;
        self::assertNotNull($blueprint);
        $allPermissions = array_keys($blueprint->permissions);
        $administrator = $blueprint->roles['event_administrator'];
        self::assertSame('Administrator', $administrator->label);
        self::assertArrayNotHasKey('administrator', $blueprint->roles);
        self::assertSame($allPermissions, $administrator->permissions);
        self::assertNotContains('*', $administrator->permissions);
        self::assertNotContains('administer everything', $administrator->permissions);

        $workflow = $blueprint->workflows['event_editorial'];
        self::assertSame('draft', $workflow->initialState);
        self::assertSame(['draft', 'published', 'review'], array_keys($workflow->states));
        self::assertSame(['publish', 'return_to_draft', 'submit_for_review'], array_keys($workflow->transitions));
        self::assertCount(1, $workflow->bindings);
        self::assertSame('event', $workflow->bindings[0]->entity);

        self::assertSame('allowed', $blueprint->checks['contributor_submits']->expect);
        self::assertSame('denied', $blueprint->checks['contributor_cannot_publish']->expect);
        self::assertSame('allowed', $blueprint->checks['reviewer_publishes']->expect);
        self::assertSame('denied', $blueprint->checks['reviewer_cannot_submit']->expect);
    }

    #[Test]
    public function business_organizers_are_not_misrepresented_as_authenticated_owners(): void
    {
        $blueprint = $this->manifest()->applicationBlueprint;
        self::assertNotNull($blueprint);
        $event = $blueprint->entities['event'];

        self::assertNull($event->keys->owner, 'A business Organizer id is not an authenticated Account id.');
        self::assertSame('workflow_state', $blueprint->policies['event_update_draft']->condition->kind->value);
        self::assertSame(['draft'], $blueprint->policies['event_update_draft']->condition->states);
        self::assertSame('permission', $blueprint->policies['event_update_manage']->condition->kind->value);
        self::assertSame('allow', $blueprint->checks['contributor_updates_draft']->expect);
        self::assertSame('deny', $blueprint->checks['contributor_cannot_update_review']->expect);
    }

    #[Test]
    public function administrator_location_authority_has_matching_crud_policies(): void
    {
        $blueprint = $this->manifest()->applicationBlueprint;
        self::assertNotNull($blueprint);

        foreach (['organizer', 'venue'] as $entity) {
            foreach (['create', 'update', 'delete'] as $operation) {
                $policy = $blueprint->policies[$entity . '_' . $operation];
                self::assertSame($entity, $policy->entity);
                self::assertSame($operation, $policy->operation->value);
                self::assertSame('manage locations', $policy->condition->permission);
            }
        }
    }

    #[Test]
    public function fixture_relationships_resolve_to_the_declared_target_entities(): void
    {
        $blueprint = $this->manifest()->applicationBlueprint;
        self::assertNotNull($blueprint);
        $event = $blueprint->fixtures['harvest_festival'];
        self::assertSame('community_hall', $event->values['venue']);
        self::assertSame('events_team', $event->values['organizer']);
        self::assertSame('venue', $blueprint->fixtures['community_hall']->entity);
        self::assertSame('organizer', $blueprint->fixtures['events_team']->entity);
        self::assertSame('review', $blueprint->fixtures['review_festival']->workflowState);
    }

    #[Test]
    public function a_missing_relationship_target_fails_with_the_canonical_finding(): void
    {
        $yaml = str_replace('venue: community_hall', 'venue: absent_venue', $this->yaml());
        try {
            new SiteManifestParser()->parse($yaml, 'community-events@1');
            self::fail('Expected the unresolved fixture relationship to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame('SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $exception->violations[0]->code);
            self::assertSame('/application_blueprint/fixtures/2/values/venue', $exception->violations[0]->path);
        }
    }

    #[Test]
    public function canonical_identity_is_stable_across_line_endings_and_render_round_trip(): void
    {
        $parser = new SiteManifestParser();
        $manifest = $parser->parse($this->yaml(), 'community-events@1');
        $crlf = $parser->parse(str_replace("\n", "\r\n", $this->yaml()), 'community-events@1-crlf');
        $roundTrip = $parser->parse($parser->render($manifest), 'community-events@1-rendered');

        self::assertSame($manifest->canonicalJson, $crlf->canonicalJson);
        self::assertSame($manifest->digest, $crlf->digest);
        self::assertSame($manifest->digest, $roundTrip->digest);
        self::assertSame($manifest->applicationBlueprint?->digest, $roundTrip->applicationBlueprint?->digest);
    }

    #[Test]
    public function packaged_readme_declares_customizations_limits_and_provenance(): void
    {
        $readme = (string) file_get_contents(dirname($this->path()).'/README.md');
        self::assertStringContainsString('Event-to-Venue and Event-to-Organizer', $readme);
        self::assertStringContainsString('Administrator has no implicit bypass', $readme);
        self::assertStringContainsString('template placeholder', $readme);
        self::assertStringContainsString('exact source reference', $readme);
    }

    private function manifest(): SiteManifest
    {
        return new SiteManifestParser()->parse($this->yaml(), 'community-events@1');
    }

    private function yaml(): string
    {
        $yaml = file_get_contents($this->path());
        self::assertIsString($yaml);

        return $yaml;
    }

    private function path(): string
    {
        return dirname(__DIR__, 3).'/resources/starters/community-events/v1.yaml';
    }
}
