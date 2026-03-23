<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase14;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Tests\Support\WorkflowFixturePack;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowPreset;

#[CoversNothing]
final class WorkflowFixturePackIntegrationTest extends TestCase
{
    private const string EXPECTED_CORPUS_HASH = '4824bc8efe74312b45e27dc94f2005a008ca71a5';

    #[Test]
    public function fixtureCorpusIsDeterministicAcrossCalls(): void
    {
        $firstHash = WorkflowFixturePack::corpusHash();
        $secondHash = WorkflowFixturePack::corpusHash();

        $this->assertSame($firstHash, $secondHash);
        $this->assertSame(self::EXPECTED_CORPUS_HASH, $firstHash);
    }

    #[Test]
    public function transitionAccessScenariosCoverRoleAndPermissionPaths(): void
    {
        $workflow = EditorialWorkflowPreset::create();
        $resolver = new EditorialTransitionAccessResolver($workflow);

        foreach (WorkflowFixturePack::transitionAccessScenarios() as $scenario) {
            $account = new FixtureScenarioAccount($scenario['permissions'], $scenario['roles']);
            $access = $resolver->canTransition(
                $scenario['bundle'],
                $scenario['from'],
                $scenario['to'],
                $account,
            );

            $this->assertSame(
                $scenario['expected_allowed'],
                $access->isAllowed(),
                sprintf('Scenario failed: %s', $scenario['name']),
            );
        }
    }

    #[Test]
    public function invalidTransitionScenariosRemainRejected(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        foreach (WorkflowFixturePack::invalidTransitionScenarios() as $scenario) {
            $this->assertFalse(
                $workflow->isTransitionAllowed($scenario['from'], $scenario['to']),
                sprintf('Expected transition to be rejected for scenario: %s', $scenario['name']),
            );
        }
    }

    #[Test]
    public function discoveryFixturesIncludeMixedWorkflowTemporalAndCrossBundleSignals(): void
    {
        $nodes = WorkflowFixturePack::discoveryNodes();
        $this->assertArrayHasKey('anchor_water', $nodes);
        $this->assertArrayHasKey('governance_draft', $nodes);
        $this->assertArrayHasKey('archive_song', $nodes);
        $this->assertSame('published', $nodes['anchor_water']['workflow_state']);
        $this->assertSame('draft', $nodes['governance_draft']['workflow_state']);
        $this->assertSame('archived', $nodes['archive_song']['workflow_state']);
        $this->assertSame('story', $nodes['river_memory']['type']);
        $this->assertSame('guide', $nodes['seasonal_calendar']['type']);

        $relationships = WorkflowFixturePack::discoveryRelationships();
        $this->assertCount(6, $relationships);
        $this->assertSame('temporal', $relationships[2]['relationship_type']);
        $this->assertNotNull($relationships[2]['end_date']);
        $this->assertSame('related', $relationships[4]['relationship_type']);
        $this->assertSame(0, $relationships[4]['status']);

        $searchScenarios = WorkflowFixturePack::discoverySearchScenarios();
        $this->assertCount(2, $searchScenarios);
        $this->assertSame('water', $searchScenarios[0]['query']);
        $this->assertContains('anchor_water', $searchScenarios[0]['expected_visible_keys']);
    }
}

final class FixtureScenarioAccount implements AccountInterface
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        private readonly array $permissions,
        private readonly array $roles,
    ) {}

    public function id(): int|string
    {
        return 1;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
