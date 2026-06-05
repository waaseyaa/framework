<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase29;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Policy\RevisionPolicyComposition;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionMetadata;
use Waaseyaa\Entity\TranslatableInterface;

/**
 * Phase 29 integration: end-to-end two-axis access policy composition
 * (M-004 / WP05 — FR-020, FR-021, FR-022, FR-023, FR-024).
 *
 * Worked example fixture from the access-policy-revision contract §4:
 * a Minoo `TeachingAccessPolicy` distinguishes Coordinator vs Knowledge-Keeper
 * access to the English vs Anishinaabemowin (oj) revision histories of a
 * `teaching` entity. The integration exercises the full composition surface:
 *
 *   1. The translation instance is the policy's `$entity` argument.
 *   2. The historical revision is available via the helper's `$revision` arg.
 *   3. `view_revision` and `translate` are composed without introducing a
 *      `view_translation_revision` operation (FR-023).
 *   4. Fallback semantics (`view_revision` → `view`, `translate` → `edit`)
 *      light up for policies that do not declare the revision-aware op.
 *
 * The same fixture pattern is the preview for the WP08 verification gate
 * (FR-044): the Minoo per-language policy validation.
 */
#[CoversNothing]
final class TwoAxisAccessPolicyIntegrationTest extends TestCase
{
    private const string LANG_EN = 'en';

    private const string LANG_OJ = 'oj';

    #[Test]
    public function coordinatorSeesEnglishRevisionHistory(): void
    {
        $teaching   = $this->makeTeaching([self::LANG_EN, self::LANG_OJ]);
        $revisionEn = $this->makeRevision(vid: 42, langcode: self::LANG_EN);
        $coordinator = $this->makeAccount(['coordinator']);

        $composer = new RevisionPolicyComposition();
        $result   = $composer->composeAccess(
            new TeachingAccessPolicy(),
            $teaching,
            $coordinator,
            'view_revision',
            $revisionEn,
        );

        self::assertTrue($result->isAllowed(), 'coordinator must see English revision history');
    }

    #[Test]
    public function coordinatorIsForbiddenFromAnishinaabemowinRevisionHistory(): void
    {
        $teaching     = $this->makeTeaching([self::LANG_EN, self::LANG_OJ]);
        $revisionOj   = $this->makeRevision(vid: 7, langcode: self::LANG_OJ);
        $coordinator  = $this->makeAccount(['coordinator']);

        $composer = new RevisionPolicyComposition();
        $result   = $composer->composeAccess(
            new TeachingAccessPolicy(),
            $teaching,
            $coordinator,
            'view_revision',
            $revisionOj,
        );

        self::assertTrue($result->isForbidden(), 'coordinator must NOT see Anishinaabemowin revision history');
    }

    #[Test]
    public function knowledgeKeeperSeesBothLanguageHistories(): void
    {
        $teaching        = $this->makeTeaching([self::LANG_EN, self::LANG_OJ]);
        $revisionEn      = $this->makeRevision(vid: 42, langcode: self::LANG_EN);
        $revisionOj      = $this->makeRevision(vid: 7, langcode: self::LANG_OJ);
        $knowledgeKeeper = $this->makeAccount(['knowledge-keeper']);

        $composer = new RevisionPolicyComposition();
        $policy   = new TeachingAccessPolicy();

        $en = $composer->composeAccess($policy, $teaching, $knowledgeKeeper, 'view_revision', $revisionEn);
        $oj = $composer->composeAccess($policy, $teaching, $knowledgeKeeper, 'view_revision', $revisionOj);

        // Knowledge-keepers are not specifically granted English coordinator-history
        // access by this policy; they fall back to `view` via the composition helper.
        // Per the fixture, `view` is open by default → Allowed.
        self::assertTrue($en->isAllowed(), 'knowledge-keeper sees English (via view fallback)');
        self::assertTrue($oj->isAllowed(), 'knowledge-keeper sees Anishinaabemowin (explicit grant)');
    }

    #[Test]
    public function policyReceivesTranslationInstanceAsEntityArg(): void
    {
        $teaching   = $this->makeTeaching([self::LANG_EN, self::LANG_OJ]);
        $revisionOj = $this->makeRevision(vid: 7, langcode: self::LANG_OJ);
        $keeper     = $this->makeAccount(['knowledge-keeper']);

        $observer = new ObservingPolicy(AccessResult::allowed());

        $composer = new RevisionPolicyComposition();
        // This test asserts on the entity the policy received, not the access
        // decision, so the #[\NoDiscard] return is intentionally ignored here.
        (void) $composer->composeAccess($observer, $teaching, $keeper, 'view_revision', $revisionOj);

        self::assertNotNull($observer->lastEntity);
        self::assertInstanceOf(TranslatableInterface::class, $observer->lastEntity);
        self::assertSame(self::LANG_OJ, $observer->lastEntity->activeLangcode());
    }

    #[Test]
    public function policyCanResolveRevisionMetadataFromCallerArg(): void
    {
        $teaching   = $this->makeTeaching([self::LANG_EN]);
        $revision   = $this->makeRevision(vid: 314, langcode: self::LANG_EN);
        $keeper     = $this->makeAccount(['knowledge-keeper']);

        // Caller-side introspection: the integration test stands in for any
        // caller (e.g. revision-history UI) that combines the helper with the
        // optional revision argument. The composer does not call any revision
        // metadata method itself; it merely routes the langcode.
        self::assertSame(314, $revision->revisionId());
        self::assertNotNull($revision->revisionMetadata());
        self::assertSame(1, $revision->revisionMetadata()->revisionAuthor);

        $composer = new RevisionPolicyComposition();
        $result   = $composer->composeAccess(new TeachingAccessPolicy(), $teaching, $keeper, 'view_revision', $revision);

        // Knowledge-keeper falls back to `view` (open by default) for English revisions.
        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function viewRevisionFallsBackToViewForPolicyThatDoesNotDeclareRevisionOp(): void
    {
        // Single-axis policy (only opines on `view`) under a two-axis entity.
        $policy = new class implements AccessPolicyInterface {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $this->seen[] = $operation;

                return $operation === 'view'
                    ? AccessResult::allowed('view always allowed')
                    : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        $teaching   = $this->makeTeaching([self::LANG_EN]);
        $revisionEn = $this->makeRevision(vid: 1, langcode: self::LANG_EN);
        $account    = $this->makeAccount(['coordinator']);

        $composer = new RevisionPolicyComposition();
        $result   = $composer->composeAccess($policy, $teaching, $account, 'view_revision', $revisionEn);

        self::assertTrue($result->isAllowed());
        self::assertSame(['view_revision', 'view'], $policy->seen);
    }

    #[Test]
    public function translateFallsBackToEditForPolicyThatDoesNotDeclareTranslate(): void
    {
        $policy = new class implements AccessPolicyInterface {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $this->seen[] = $operation;

                return $operation === 'edit'
                    ? AccessResult::allowed('edit allowed')
                    : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        $teaching = $this->makeTeaching([self::LANG_EN, self::LANG_OJ]);
        $account  = $this->makeAccount(['coordinator']);

        $composer = new RevisionPolicyComposition();
        $result   = $composer->composeAccess($policy, $teaching, $account, 'translate');

        self::assertTrue($result->isAllowed());
        self::assertSame(['translate', 'edit'], $policy->seen);
    }

    /**
     * @param string[] $langcodes
     */
    private function makeTeaching(array $langcodes): EntityInterface&TranslatableInterface
    {
        return new TestTeaching($langcodes);
    }

    private function makeRevision(int $vid, string $langcode): RevisionableEntityInterface&TranslatableInterface
    {
        return new TestTeachingRevision($vid, $langcode);
    }

    /**
     * @param string[] $roles
     */
    private function makeAccount(array $roles): AccountInterface
    {
        return new TestAccount($roles);
    }
}

/**
 * @internal Fixture for {@see TwoAxisAccessPolicyIntegrationTest}.
 */
final class TeachingAccessPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $langcode = $entity instanceof TranslatableInterface ? $entity->activeLangcode() : 'en';

        // English revision history: Coordinator or Knowledge-Keeper allowed.
        if ($operation === 'view_revision' && $langcode === 'en') {
            return in_array('coordinator', $account->getRoles(), true)
                ? AccessResult::allowed('coordinator views english history')
                : AccessResult::neutral();
        }

        // Anishinaabemowin revision history: Knowledge-Keeper only; Coordinator forbidden.
        if ($operation === 'view_revision' && $langcode === 'oj') {
            if (in_array('knowledge-keeper', $account->getRoles(), true)) {
                return AccessResult::allowed('keeper views oj history');
            }

            return AccessResult::forbidden('oj history is keeper-only');
        }

        // Translate is open to coordinator or knowledge-keeper.
        if ($operation === 'translate') {
            if (in_array('coordinator', $account->getRoles(), true)
                || in_array('knowledge-keeper', $account->getRoles(), true)
            ) {
                return AccessResult::allowed('role can translate');
            }

            return AccessResult::neutral();
        }

        // Default view is open by design (the public can read teachings).
        if ($operation === 'view') {
            return AccessResult::allowed('teachings are public');
        }

        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'teaching';
    }
}

/**
 * @internal Fixture: policy that just records what was asked of it.
 */
final class ObservingPolicy implements AccessPolicyInterface
{
    public ?EntityInterface $lastEntity = null;

    public function __construct(private readonly AccessResult $result) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $this->lastEntity = $entity;

        return $this->result;
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}

/**
 * @internal Fixture entity that implements the EntityInterface + TranslatableInterface
 *           contract without dragging in the full ContentEntityBase storage surface.
 */
final class TestTeaching implements EntityInterface, TranslatableInterface
{
    private string $activeLangcode;

    /**
     * @param string[] $langcodes
     */
    public function __construct(private readonly array $langcodes)
    {
        $this->activeLangcode = $langcodes[0];
    }

    public function id(): int|string|null
    {
        return 42;
    }

    public function uuid(): string
    {
        return '00000000-0000-4000-8000-000000000042';
    }

    public function label(): string
    {
        return 'teaching';
    }

    public function getEntityTypeId(): string
    {
        return 'teaching';
    }

    public function bundle(): string
    {
        return 'teaching';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return null;
    }

    public function set(string $name, mixed $value): static
    {
        return $this;
    }

    public function toArray(): array
    {
        return [];
    }

    public function language(): string
    {
        return $this->activeLangcode;
    }

    public function defaultLangcode(): string
    {
        return $this->langcodes[0];
    }

    public function activeLangcode(): string
    {
        return $this->activeLangcode;
    }

    public function hasTranslation(string $langcode): bool
    {
        return in_array($langcode, $this->langcodes, true);
    }

    public function getTranslation(string $langcode): static
    {
        $clone                 = clone $this;
        $clone->activeLangcode = $langcode;

        return $clone;
    }

    public function addTranslation(string $langcode): static
    {
        throw new \LogicException('not used in this fixture');
    }

    public function removeTranslation(string $langcode): void
    {
        throw new \LogicException('not used in this fixture');
    }

    public function translations(): iterable
    {
        yield from $this->langcodes;
    }

    public function getTranslationLanguages(): array
    {
        return $this->langcodes;
    }

    public function fieldLangcode(string $fieldName): ?string
    {
        return $this->activeLangcode;
    }
}

/**
 * @internal Fixture revision that carries the langcode used for translation routing.
 */
final class TestTeachingRevision implements EntityInterface, RevisionableEntityInterface, TranslatableInterface
{
    public function __construct(
        private readonly int $vid,
        private readonly string $activeLangcode,
    ) {}

    public function id(): int|string|null
    {
        return 42;
    }

    public function uuid(): string
    {
        return '00000000-0000-4000-8000-000000000042';
    }

    public function label(): string
    {
        return 'teaching revision';
    }

    public function getEntityTypeId(): string
    {
        return 'teaching';
    }

    public function bundle(): string
    {
        return 'teaching';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return null;
    }

    public function set(string $name, mixed $value): static
    {
        return $this;
    }

    public function toArray(): array
    {
        return [];
    }

    public function language(): string
    {
        return $this->activeLangcode;
    }

    public function revisionId(): int|string|null
    {
        return $this->vid;
    }

    public function isCurrentRevision(): bool
    {
        return false;
    }

    public function revisionMetadata(): ?RevisionMetadata
    {
        return new RevisionMetadata(
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            1,
            "revision {$this->vid}",
        );
    }

    public function defaultLangcode(): string
    {
        return 'en';
    }

    public function activeLangcode(): string
    {
        return $this->activeLangcode;
    }

    public function hasTranslation(string $langcode): bool
    {
        return $langcode === $this->activeLangcode;
    }

    public function getTranslation(string $langcode): static
    {
        return $this;
    }

    public function addTranslation(string $langcode): static
    {
        throw new \LogicException('not used in this fixture');
    }

    public function removeTranslation(string $langcode): void
    {
        throw new \LogicException('not used in this fixture');
    }

    public function translations(): iterable
    {
        yield $this->activeLangcode;
    }

    public function getTranslationLanguages(): array
    {
        return [$this->activeLangcode];
    }

    public function fieldLangcode(string $fieldName): ?string
    {
        return $this->activeLangcode;
    }
}

/**
 * @internal Fixture account that exposes a simple role list.
 */
final class TestAccount implements AccountInterface
{
    /**
     * @param string[] $roles
     */
    public function __construct(private readonly array $roles) {}

    public function id(): int|string
    {
        return 1;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
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
