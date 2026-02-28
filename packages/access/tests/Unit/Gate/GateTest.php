<?php

declare(strict_types=1);

namespace Aurora\Access\Tests\Unit\Gate;

use Aurora\Access\Gate\AccessDeniedException;
use Aurora\Access\Gate\Gate;
use Aurora\Access\Gate\GateInterface;
use Aurora\Access\Gate\PolicyAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gate::class)]
#[CoversClass(AccessDeniedException::class)]
#[CoversClass(PolicyAttribute::class)]
final class GateTest extends TestCase
{
    // ---------------------------------------------------------------
    // Interface contract
    // ---------------------------------------------------------------

    #[Test]
    public function itImplementsGateInterface(): void
    {
        $gate = new Gate();

        $this->assertInstanceOf(GateInterface::class, $gate);
    }

    // ---------------------------------------------------------------
    // allows() tests
    // ---------------------------------------------------------------

    #[Test]
    public function allowsReturnsFalseWhenNoPoliciesRegistered(): void
    {
        $gate = new Gate();

        $this->assertFalse($gate->allows('view', 'node'));
    }

    #[Test]
    public function allowsReturnsTrueWhenPolicyGrantsAccess(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertTrue($gate->allows('view', 'node'));
    }

    #[Test]
    public function allowsReturnsFalseWhenPolicyDeniesAccess(): void
    {
        $policy = new NodePolicyFixture(viewResult: false);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', 'node'));
    }

    #[Test]
    public function allowsPassesUserAndSubjectToPolicy(): void
    {
        $user = new \stdClass();
        $user->name = 'admin';

        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $entity = $this->createEntityStub('node');
        $gate->allows('view', $entity, $user);

        $this->assertSame($user, $policy->lastUser);
        $this->assertSame($entity, $policy->lastSubject);
    }

    #[Test]
    public function allowsPassesNullUserWhenNotProvided(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $gate->allows('view', 'node');

        $this->assertNull($policy->lastUser);
    }

    #[Test]
    public function allowsReturnsFalseWhenAbilityMethodDoesNotExist(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('nonexistent', 'node'));
    }

    #[Test]
    public function allowsReturnsFalseWhenNoPolicyMatchesSubject(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', 'user'));
    }

    #[Test]
    public function allowsResolvesEntityTypeFromObject(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $entity = $this->createEntityStub('node');

        $this->assertTrue($gate->allows('view', $entity));
    }

    #[Test]
    public function allowsReturnsFalseForObjectWithoutGetEntityTypeId(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', new \stdClass()));
    }

    #[Test]
    public function allowsCallsCorrectAbilityMethod(): void
    {
        $policy = new NodePolicyFixture(viewResult: false, updateResult: true, deleteResult: false);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', 'node'));
        $this->assertTrue($gate->allows('update', 'node'));
        $this->assertFalse($gate->allows('delete', 'node'));
    }

    // ---------------------------------------------------------------
    // denies() tests
    // ---------------------------------------------------------------

    #[Test]
    public function deniesReturnsTrueWhenNoPoliciesRegistered(): void
    {
        $gate = new Gate();

        $this->assertTrue($gate->denies('view', 'node'));
    }

    #[Test]
    public function deniesReturnsFalseWhenPolicyGrantsAccess(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->denies('view', 'node'));
    }

    #[Test]
    public function deniesReturnsTrueWhenPolicyDeniesAccess(): void
    {
        $policy = new NodePolicyFixture(viewResult: false);
        $gate = new Gate(policies: [$policy]);

        $this->assertTrue($gate->denies('view', 'node'));
    }

    #[Test]
    public function deniesIsInverseOfAllows(): void
    {
        $policy = new NodePolicyFixture(viewResult: true, updateResult: false);
        $gate = new Gate(policies: [$policy]);

        $this->assertNotSame($gate->allows('view', 'node'), $gate->denies('view', 'node'));
        $this->assertNotSame($gate->allows('update', 'node'), $gate->denies('update', 'node'));
    }

    // ---------------------------------------------------------------
    // authorize() tests
    // ---------------------------------------------------------------

    #[Test]
    public function authorizeDoesNotThrowWhenAllowed(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $gate->authorize('view', 'node');

        // If we reach here, no exception was thrown.
        $this->assertTrue(true);
    }

    #[Test]
    public function authorizeThrowsWhenDenied(): void
    {
        $policy = new NodePolicyFixture(viewResult: false);
        $gate = new Gate(policies: [$policy]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied for ability "view"');

        $gate->authorize('view', 'node');
    }

    #[Test]
    public function authorizeThrowsWhenNoPolicyExists(): void
    {
        $gate = new Gate();

        $this->expectException(AccessDeniedException::class);

        $gate->authorize('view', 'node');
    }

    #[Test]
    public function accessDeniedExceptionCarriesAbilityAndSubject(): void
    {
        $gate = new Gate();

        try {
            $gate->authorize('delete', 'node');
            $this->fail('Expected AccessDeniedException was not thrown.');
        } catch (AccessDeniedException $e) {
            $this->assertSame('delete', $e->ability);
            $this->assertSame('node', $e->subject);
        }
    }

    #[Test]
    public function accessDeniedExceptionMessageIncludesSubjectType(): void
    {
        $entity = $this->createEntityStub('node');
        $gate = new Gate();

        try {
            $gate->authorize('view', $entity);
            $this->fail('Expected AccessDeniedException was not thrown.');
        } catch (AccessDeniedException $e) {
            $this->assertStringContainsString('view', $e->getMessage());
        }
    }

    #[Test]
    public function accessDeniedExceptionForStringSubject(): void
    {
        $exception = new AccessDeniedException(ability: 'update', subject: 'node');

        $this->assertStringContainsString('update', $exception->getMessage());
        $this->assertStringContainsString('string', $exception->getMessage());
    }

    // ---------------------------------------------------------------
    // PolicyAttribute resolution
    // ---------------------------------------------------------------

    #[Test]
    public function resolvesPolicyByAttribute(): void
    {
        $policy = new TaxonomyTermPolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertTrue($gate->allows('view', 'taxonomy_term'));
    }

    #[Test]
    public function attributeResolutionTakesPrecedenceOverNaming(): void
    {
        // The class AttributeOverridesNamePolicy has both:
        // - Short name "AttributeOverridesNamePolicy" -> convention would yield "attribute_overrides_name"
        // - Attribute says "media"
        $policy = new AttributeOverridesNamePolicy();
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', 'attribute_overrides_name'));
        $this->assertTrue($gate->allows('view', 'media'));
    }

    // ---------------------------------------------------------------
    // Naming convention resolution
    // ---------------------------------------------------------------

    #[Test]
    public function resolvesPolicyByNamingConvention(): void
    {
        // "CommentPolicy" short name -> strips "Policy" -> "Comment" -> "comment"
        $policy = new CommentPolicy();
        $gate = new Gate(policies: [$policy]);

        $this->assertTrue($gate->allows('view', 'comment'));
    }

    #[Test]
    public function resolvesPascalCaseToSnakeCase(): void
    {
        // "TaxonomyVocabularyPolicy" -> strips "Policy" -> "TaxonomyVocabulary" -> "taxonomy_vocabulary"
        $policy = new TaxonomyVocabularyPolicy();
        $gate = new Gate(policies: [$policy]);

        $this->assertTrue($gate->allows('view', 'taxonomy_vocabulary'));
    }

    // ---------------------------------------------------------------
    // Multiple policies
    // ---------------------------------------------------------------

    #[Test]
    public function multiplePoliciesForDifferentEntityTypes(): void
    {
        $nodePolicy = new NodePolicyFixture(viewResult: true, updateResult: false);
        $userPolicy = new UserPolicyFixture(viewResult: false, updateResult: true);
        $gate = new Gate(policies: [$nodePolicy, $userPolicy]);

        $this->assertTrue($gate->allows('view', 'node'));
        $this->assertFalse($gate->allows('update', 'node'));
        $this->assertFalse($gate->allows('view', 'user'));
        $this->assertTrue($gate->allows('update', 'user'));
    }

    #[Test]
    public function lastPolicyWinsWhenMultipleForSameEntityType(): void
    {
        $policy1 = new NodePolicyFixture(viewResult: false);
        $policy2 = new AnotherNodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy1, $policy2]);

        // The second policy for "node" (via attribute) overwrites the first.
        $this->assertTrue($gate->allows('view', 'node'));
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    #[Test]
    public function allowsReturnsFalseForNullSubject(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', null));
    }

    #[Test]
    public function allowsReturnsFalseForIntegerSubject(): void
    {
        $policy = new NodePolicyFixture(viewResult: true);
        $gate = new Gate(policies: [$policy]);

        $this->assertFalse($gate->allows('view', 42));
    }

    #[Test]
    public function policyAttributeEntityTypeProperty(): void
    {
        $attr = new PolicyAttribute(entityType: 'node');

        $this->assertSame('node', $attr->entityType);
    }

    #[Test]
    public function emptyPoliciesArrayConstructsSuccessfully(): void
    {
        $gate = new Gate(policies: []);

        $this->assertInstanceOf(Gate::class, $gate);
        $this->assertFalse($gate->allows('view', 'anything'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createEntityStub(string $entityTypeId): object
    {
        return new class($entityTypeId) {
            public function __construct(private readonly string $entityTypeId) {}

            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }
        };
    }
}

// ---------------------------------------------------------------
// Test policy fixtures (using PolicyAttribute)
// ---------------------------------------------------------------

#[PolicyAttribute(entityType: 'node')]
final class NodePolicyFixture
{
    public ?object $lastUser = null;
    public mixed $lastSubject = null;

    public function __construct(
        private readonly bool $viewResult = false,
        private readonly bool $updateResult = false,
        private readonly bool $deleteResult = false,
    ) {}

    public function view(?object $user, mixed $subject): bool
    {
        $this->lastUser = $user;
        $this->lastSubject = $subject;

        return $this->viewResult;
    }

    public function update(?object $user, mixed $subject): bool
    {
        $this->lastUser = $user;
        $this->lastSubject = $subject;

        return $this->updateResult;
    }

    public function delete(?object $user, mixed $subject): bool
    {
        $this->lastUser = $user;
        $this->lastSubject = $subject;

        return $this->deleteResult;
    }
}

#[PolicyAttribute(entityType: 'taxonomy_term')]
final class TaxonomyTermPolicyFixture
{
    public function __construct(
        private readonly bool $viewResult = false,
    ) {}

    public function view(?object $user, mixed $subject): bool
    {
        return $this->viewResult;
    }
}

#[PolicyAttribute(entityType: 'user')]
final class UserPolicyFixture
{
    public function __construct(
        private readonly bool $viewResult = false,
        private readonly bool $updateResult = false,
    ) {}

    public function view(?object $user, mixed $subject): bool
    {
        return $this->viewResult;
    }

    public function update(?object $user, mixed $subject): bool
    {
        return $this->updateResult;
    }
}

#[PolicyAttribute(entityType: 'node')]
final class AnotherNodePolicyFixture
{
    public function __construct(
        private readonly bool $viewResult = false,
    ) {}

    public function view(?object $user, mixed $subject): bool
    {
        return $this->viewResult;
    }
}

// ---------------------------------------------------------------
// Test policy fixtures (using naming convention, NO attribute)
// ---------------------------------------------------------------

/**
 * Named "CommentPolicy" -> Gate strips "Policy" -> "Comment" -> snake_case "comment".
 */
final class CommentPolicy
{
    public function view(?object $user, mixed $subject): bool
    {
        return true;
    }
}

/**
 * Named "TaxonomyVocabularyPolicy" -> strips "Policy" -> "TaxonomyVocabulary" -> "taxonomy_vocabulary".
 */
final class TaxonomyVocabularyPolicy
{
    public function view(?object $user, mixed $subject): bool
    {
        return true;
    }
}

/**
 * Has both a PolicyAttribute (entityType: 'media') and a class name that
 * would conventionally resolve to "attribute_overrides_name".
 * The attribute should take precedence.
 */
#[PolicyAttribute(entityType: 'media')]
final class AttributeOverridesNamePolicy
{
    public function view(?object $user, mixed $subject): bool
    {
        return true;
    }
}
