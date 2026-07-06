<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Genealogy\Access\GenealogyContentAccessPolicy;
use Waaseyaa\Genealogy\Entity\GenealogyEvent;
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\GenealogyBootstrap;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;

/**
 * genealogy m-a (security): the living-person concealment guard in
 * {@see GenealogyContentAccessPolicy} was gated behind
 * `getEntityTypeId() === 'genealogy_person'`, so `genealogy_family` and
 * `genealogy_event` — both carrying a REQUIRED free-text `display_name` that in
 * practice names living people — fell through to `allowed()` for anonymous
 * viewers on published-entity + published-tree alone. `genealogy_family` is
 * anonymously SSR-viewable via the `family()` route, and both are
 * crawler-enumerable (they were omitted from
 * {@see \Waaseyaa\SSR\Http\SeoPublicController}'s `NON_PUBLIC_TYPES}).
 *
 * The `display_name` free-text channel has no living/deceased axis of its own
 * (unlike `genealogy_person`'s `is_living`), so the guard fails CLOSED for
 * family/event: a non-owner/anonymous viewer is refused even when the row and
 * its tree are published. Tree owners and the built-in dev `DevAdminAccount`
 * keep access.
 */
final class GenealogyFamilyEventConcealmentTest extends TestCase
{
    protected function tearDown(): void
    {
        GenealogyBootstrap::reset();
    }

    private function bindPublishedTree(int $treeId, int $ownerUid = 99): void
    {
        $tree = new GenealogyTree([
            'id' => $treeId,
            'display_name' => 'Fixture tree',
            'status' => 1,
            'owner_uid' => $ownerUid,
        ]);

        $treeRepository = $this->createMock(EntityRepositoryInterface::class);
        $treeRepository->method('find')->with((string) $treeId)->willReturn($tree);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getRepository')->with('genealogy_tree')->willReturn($treeRepository);

        GenealogyBootstrap::bind($etm, null);
    }

    #[Test]
    public function anonymous_cannot_view_published_family_naming_a_living_person(): void
    {
        $this->bindPublishedTree(1);
        $policy = new GenealogyContentAccessPolicy();

        $family = new GenealogyFamily([
            'display_name' => 'Wedding of Living Alice and Living Bob',
            'status' => 1,
            'tree_id' => 1,
        ]);

        $result = $policy->access($family, 'view', new AnonymousUser());

        self::assertTrue($result->isForbidden(), 'a published family naming living people must not be anonymously viewable');
    }

    #[Test]
    public function anonymous_cannot_view_published_event_naming_a_living_person(): void
    {
        $this->bindPublishedTree(1);
        $policy = new GenealogyContentAccessPolicy();

        $event = new GenealogyEvent([
            'display_name' => 'Baptism of Living Carol',
            'status' => 1,
            'tree_id' => 1,
        ]);

        $result = $policy->access($event, 'view', new AnonymousUser());

        self::assertTrue($result->isForbidden(), 'a published event naming a living person must not be anonymously viewable');
    }

    #[Test]
    public function authenticated_non_owner_cannot_view_published_family(): void
    {
        $this->bindPublishedTree(1, ownerUid: 99);
        $policy = new GenealogyContentAccessPolicy();

        // A signed-in user who does NOT own the tree (uid 7 != owner 99).
        $account = new User(['uid' => 7]);

        $family = new GenealogyFamily([
            'display_name' => 'The Living Household',
            'status' => 1,
            'tree_id' => 1,
        ]);

        $result = $policy->access($family, 'view', $account);

        self::assertTrue($result->isForbidden(), 'a non-owner must not view a family free-text display_name channel');
    }

    #[Test]
    public function tree_owner_may_still_view_their_family(): void
    {
        $this->bindPublishedTree(1, ownerUid: 7);
        $policy = new GenealogyContentAccessPolicy();

        $owner = new User(['uid' => 7]);

        $family = new GenealogyFamily([
            'display_name' => 'The Owner Household',
            'status' => 1,
            'tree_id' => 1,
        ]);

        $result = $policy->access($family, 'view', $owner);

        self::assertTrue($result->isAllowed(), 'the tree owner keeps access to their own family record');
    }

    #[Test]
    public function dev_admin_may_still_view_family(): void
    {
        $this->bindPublishedTree(1);
        $policy = new GenealogyContentAccessPolicy();

        $family = new GenealogyFamily([
            'display_name' => 'Any Household',
            'status' => 1,
            'tree_id' => 1,
        ]);

        $result = $policy->access($family, 'view', new DevAdminAccount());

        self::assertTrue($result->isAllowed(), 'the built-in dev admin account keeps access (local demo)');
    }

    #[Test]
    public function anonymous_deceased_person_view_is_unchanged(): void
    {
        // Positive control: the person path is untouched — a published deceased
        // person under a published tree is still anonymously viewable.
        $this->bindPublishedTree(1);
        $policy = new GenealogyContentAccessPolicy();

        $person = new GenealogyPerson([
            'display_name' => 'Nokomis Deceased',
            'status' => 1,
            'tree_id' => 1,
            'is_living' => false,
        ]);

        $result = $policy->access($person, 'view', new AnonymousUser());

        self::assertTrue($result->isAllowed(), 'a published deceased person stays anonymously viewable');
    }
}
