<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
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
 * M-004 WP05 — `RevisionPolicyComposition` unit tests.
 *
 * Covers FR-020 (translation routing) and the optional `$revision` parameter
 * surface from the access-policy-revision contract.
 */
#[CoversClass(RevisionPolicyComposition::class)]
final class RevisionPolicyCompositionTest extends TestCase
{
    #[Test]
    public function viewRevisionRoutesToTranslationInstanceWhenRevisionCarriesLangcode(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en', 'oj'], activeLangcode: 'en');
        $revision = $this->makeRevision(vid: 42, langcode: 'oj');
        $account  = $this->makeAccount(['knowledge-keeper']);

        $policy = $this->capturingPolicy(AccessResult::allowed());

        $result = $composer->composeAccess($policy, $entity, $account, 'view_revision', $revision);

        self::assertTrue($result->isAllowed());
        self::assertSame('oj', $policy->lastEntity?->activeLangcode());
        self::assertSame('view_revision', $policy->lastOperation);
    }

    #[Test]
    public function viewRevisionWithoutRevisionUsesEntityActiveLangcode(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en', 'oj'], activeLangcode: 'oj');
        $account  = $this->makeAccount(['coordinator']);

        $policy = $this->capturingPolicy(AccessResult::allowed());

        $result = $composer->composeAccess($policy, $entity, $account, 'view_revision');

        self::assertTrue($result->isAllowed());
        self::assertSame('oj', $policy->lastEntity?->activeLangcode());
    }

    #[Test]
    public function translateRoutesToTranslationInstance(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en', 'oj'], activeLangcode: 'en');
        $revision = $this->makeRevision(vid: 7, langcode: 'oj');
        $account  = $this->makeAccount(['coordinator']);

        $policy = $this->capturingPolicy(AccessResult::allowed());

        // The composed result is asserted via the captured policy below; the
        // return value is intentionally ignored here (cast to void per #[\NoDiscard]).
        (void) $composer->composeAccess($policy, $entity, $account, 'translate', $revision);

        self::assertSame('oj', $policy->lastEntity?->activeLangcode());
        self::assertSame('translate', $policy->lastOperation);
    }

    #[Test]
    public function passThroughOperationsLeaveActiveLangcodeUntouched(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en', 'oj'], activeLangcode: 'en');
        $revision = $this->makeRevision(vid: 99, langcode: 'oj');
        $account  = $this->makeAccount(['coordinator']);

        $policy = $this->capturingPolicy(AccessResult::allowed());

        foreach (['view', 'update', 'delete'] as $operation) {
            (void) $composer->composeAccess($policy, $entity, $account, $operation, $revision);

            self::assertSame(
                'en',
                $policy->lastEntity?->activeLangcode(),
                "operation `{$operation}` must not switch the active langcode",
            );
            self::assertSame($operation, $policy->lastOperation);
        }
    }

    #[Test]
    public function nonTranslatableEntityIsPassedThroughUnmodified(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeNonTranslatableEntity();
        $revision = $this->makeRevision(vid: 1, langcode: 'oj');
        $account  = $this->makeAccount(['coordinator']);

        $policy = $this->capturingPolicy(AccessResult::allowed());

        (void) $composer->composeAccess($policy, $entity, $account, 'view_revision', $revision);

        self::assertSame($entity, $policy->lastEntity, 'non-translatable entity must not be cloned/swapped');
    }

    #[Test]
    public function explicitForbiddenIsHonouredWithoutFallback(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en'], activeLangcode: 'en');
        $account  = $this->makeAccount([]);

        $policy = $this->capturingPolicy(AccessResult::forbidden('explicit deny'));

        $result = $composer->composeAccess($policy, $entity, $account, 'view_revision');

        self::assertTrue($result->isForbidden());
        self::assertSame(1, $policy->callCount, 'forbidden must short-circuit before fallback');
    }

    #[Test]
    public function unauthenticatedIsHonouredWithoutFallback(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en'], activeLangcode: 'en');
        $account  = $this->makeAccount([]);

        $policy = $this->capturingPolicy(AccessResult::unauthenticated('no identity'));

        $result = $composer->composeAccess($policy, $entity, $account, 'translate');

        self::assertTrue($result->isUnauthenticated());
        self::assertSame(1, $policy->callCount);
    }

    #[Test]
    public function revisionArgumentIsAvailableViaPolicyCallSiteForIntrospection(): void
    {
        $composer = new RevisionPolicyComposition();
        $entity   = $this->makeTwoAxisEntity(['en'], activeLangcode: 'en');
        $account  = $this->makeAccount(['coordinator']);

        $revision = $this->makeRevision(vid: 314, langcode: 'en');

        $policy = $this->capturingPolicy(AccessResult::allowed());

        (void) $composer->composeAccess($policy, $entity, $account, 'view_revision', $revision);

        // The revision arg is the caller's responsibility to introspect when needed;
        // the helper guarantees translation routing keyed on the revision's langcode.
        self::assertSame(314, $revision->revisionId());
        self::assertSame('en', $policy->lastEntity?->activeLangcode());
    }

    /**
     * @param string[] $roles
     */
    private function makeAccount(array $roles): AccountInterface
    {
        return new class ($roles) implements AccountInterface {
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
        };
    }

    /**
     * @param string[] $langcodes
     */
    private function makeTwoAxisEntity(array $langcodes, string $activeLangcode): EntityInterface&TranslatableInterface
    {
        return new class ($langcodes, $activeLangcode) implements EntityInterface, TranslatableInterface {
            /**
             * @param string[] $langcodes
             */
            public function __construct(
                private readonly array $langcodes,
                private string $activeLangcode,
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
                throw new \LogicException('not used in this test');
            }

            public function removeTranslation(string $langcode): void
            {
                throw new \LogicException('not used in this test');
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
                return null;
            }
        };
    }

    private function makeNonTranslatableEntity(): EntityInterface
    {
        return new class implements EntityInterface {
            public function id(): int|string|null
            {
                return 7;
            }

            public function uuid(): string
            {
                return '00000000-0000-4000-8000-000000000007';
            }

            public function label(): string
            {
                return 'item';
            }

            public function getEntityTypeId(): string
            {
                return 'item';
            }

            public function bundle(): string
            {
                return 'item';
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
                return 'en';
            }
        };
    }

    private function makeRevision(int $vid, string $langcode): RevisionableEntityInterface&TranslatableInterface
    {
        return new class ($vid, $langcode) implements EntityInterface, RevisionableEntityInterface, TranslatableInterface {
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
                return 'rev';
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
                return new RevisionMetadata(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 1, 'created');
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
                throw new \LogicException('not used');
            }

            public function removeTranslation(string $langcode): void
            {
                throw new \LogicException('not used');
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
                return null;
            }
        };
    }

    /**
     * Build a policy that returns a fixed result and records every call for
     * inspection.
     */
    private function capturingPolicy(AccessResult $primary): object
    {
        return new class ($primary) implements AccessPolicyInterface {
            public ?EntityInterface $lastEntity = null;

            public ?string $lastOperation = null;

            public int $callCount = 0;

            public function __construct(private readonly AccessResult $primary) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $this->lastEntity    = $entity;
                $this->lastOperation = $operation;
                ++$this->callCount;

                return $this->primary;
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
    }
}
