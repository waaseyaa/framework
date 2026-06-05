<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationClearanceCheckerInterface;
use Waaseyaa\Field\Classification\ClassificationLabelRegistryInterface;
use Waaseyaa\Field\Classification\Permissions;
use Waaseyaa\Field\Classification\Policy\ClassificationFieldAccessPolicy;
use Waaseyaa\Field\Entity\ClassificationLabelDefinition;

#[CoversClass(ClassificationFieldAccessPolicy::class)]
final class ClassificationFieldAccessPolicyTest extends TestCase
{
    #[Test]
    public function wildcard_registration_applies_to_every_entity_type(): void
    {
        $policy = $this->policy(labels: [], clearanceLevels: []);

        self::assertTrue($policy->appliesTo('node'));
        self::assertTrue($policy->appliesTo('media'));
        self::assertTrue($policy->appliesTo('classification_label_definition'));
    }

    #[Test]
    public function explicit_entity_type_list_scopes_appliesTo(): void
    {
        // Verifies the per-type fallback documented in WP02 T-G.
        $policy = $this->policy(
            labels: [],
            clearanceLevels: [],
            entityTypes: ['node', 'media'],
        );

        self::assertTrue($policy->appliesTo('node'));
        self::assertTrue($policy->appliesTo('media'));
        self::assertFalse($policy->appliesTo('user'));
    }

    #[Test]
    public function entity_without_classification_label_returns_neutral(): void
    {
        $policy = $this->policy(labels: [], clearanceLevels: []);

        $result = $policy->access(
            $this->entity(null),
            'view',
            $this->account(['admin']),
        );

        self::assertTrue($result->isNeutral(), "Expected Neutral for unlabeled entity, got {$result->status->name}");
    }

    #[Test]
    public function unknown_operations_short_circuit_to_neutral(): void
    {
        $policy = $this->policy(labels: ['hold-legal' => 60], clearanceLevels: []);

        // 'restore' is not in the {view, update, delete} set — policy must defer
        // rather than spuriously block the operation.
        $result = $policy->access(
            $this->entity('hold-legal'),
            'restore',
            $this->account(['admin']),
        );

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function create_access_is_always_neutral(): void
    {
        // Create precedes label assignment so this policy never opines on create.
        $policy = $this->policy(labels: [], clearanceLevels: []);

        $result = $policy->createAccess('node', 'article', $this->account([]));

        self::assertTrue($result->isNeutral());
    }

    /**
     * Hold-override smoke (FR-013, C-004). The verification gate references
     * this case directly: an admin WITHOUT `legal-hold-bypass` reading a
     * `hold-legal` entity MUST get Forbidden.
     */
    #[Test]
    public function hold_override_blocks_admin_without_bypass(): void
    {
        $policy = $this->policy(
            labels: ['hold-legal' => 60],
            clearanceLevels: ['admin' => 10],
        );

        $result = $policy->fieldAccess(
            $this->entity('hold-legal'),
            'title',
            'view',
            $this->account(['admin']), // admin role only, NO bypass permission
        );

        self::assertTrue(
            $result->isForbidden(),
            "Hold-override (C-004 / FR-013) failed: admin without "
            . "'legal-hold-bypass' permission must be forbidden from reading "
            . "'hold-legal' entities. Got {$result->status->name}: '{$result->reason}'.",
        );
    }

    /**
     * @param array{0: list<string>, 1: array<string, true>, 2: string|null, 3: string}  $accountSpec
     *               [roles, permissions(set), label, expectedStatusName]
     */
    #[Test]
    #[DataProvider('accessMatrix')]
    public function table_driven_access_matrix(string $description, array $accountSpec): void
    {
        [$roles, $permissions, $labelId, $expectedStatusName] = $accountSpec;

        $policy = $this->policy(
            labels: [
                'public' => 0,
                'internal' => 10,
                'confidential' => 20,
                'hold-legal' => 60,
            ],
            clearanceLevels: [
                'admin' => 10,
                'editor' => 5,
                'viewer' => 1,
            ],
        );

        $result = $policy->fieldAccess(
            $this->entity($labelId),
            'body',
            'view',
            $this->account($roles, $permissions),
        );

        self::assertSame(
            $expectedStatusName,
            $result->status->name,
            "[$description] expected {$expectedStatusName} but got {$result->status->name}: '{$result->reason}'",
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: array{0: list<string>, 1: array<string, true>, 2: string|null, 3: string}}>
     */
    public static function accessMatrix(): iterable
    {
        // Each row: [description, [roles, permissions-as-set, labelId, expected AccessStatus name]]
        yield 'anonymous reads confidential' => [
            'anonymous account reading confidential entity',
            [[], [], 'confidential', 'FORBIDDEN'],
        ];
        yield 'viewer reads internal' => [
            'viewer (1) reads internal (10) — under-cleared',
            [['viewer'], [], 'internal', 'FORBIDDEN'],
        ];
        yield 'viewer reads public' => [
            'viewer (1) reads public (0) — sufficient',
            [['viewer'], [], 'public', 'NEUTRAL'],
        ];
        yield 'admin without bypass reads hold-legal' => [
            'admin (10) but no legal-hold-bypass reading hold-legal',
            [['admin'], [], 'hold-legal', 'FORBIDDEN'],
        ];
        yield 'admin WITH bypass reads hold-legal' => [
            'admin (10) with legal-hold-bypass reading hold-legal',
            [['admin'], [Permissions::LEGAL_HOLD_BYPASS => true], 'hold-legal', 'NEUTRAL'],
        ];
        yield 'admin reads public' => [
            'admin (10) reads public (0)',
            [['admin'], [], 'public', 'NEUTRAL'],
        ];
        yield 'editor reads confidential' => [
            'editor (5) reads confidential (20) — under-cleared',
            [['editor'], [], 'confidential', 'FORBIDDEN'],
        ];
        yield 'admin reads confidential' => [
            'admin (10) reads confidential (20) — under-cleared',
            [['admin'], [], 'confidential', 'FORBIDDEN'],
        ];
    }

    #[Test]
    public function unknown_label_is_neutral_not_forbidden(): void
    {
        // A misconfigured policy must not silently lock everyone out of an
        // otherwise-legitimate entity. Unknown labels return Neutral.
        $policy = $this->policy(labels: [], clearanceLevels: ['admin' => 10]);

        $result = $policy->access(
            $this->entity('mystery-label'),
            'view',
            $this->account(['admin']),
        );

        self::assertTrue($result->isNeutral());
    }

    // ---- Test helpers ----------------------------------------------------

    /**
     * @param array<string, int>  $labels           label_id => confidentiality_level
     * @param array<string, int>  $clearanceLevels  role => clearance
     * @param list<string>        $entityTypes
     */
    private function policy(
        array $labels,
        array $clearanceLevels,
        array $entityTypes = ['*'],
    ): ClassificationFieldAccessPolicy {
        return new ClassificationFieldAccessPolicy(
            labels: $this->labelRegistry($labels),
            clearance: $this->clearanceChecker($clearanceLevels),
            entityTypes: $entityTypes,
        );
    }

    /**
     * @param array<string, int> $labels
     */
    private function labelRegistry(array $labels): ClassificationLabelRegistryInterface
    {
        return new class($labels) implements ClassificationLabelRegistryInterface {
            /** @param array<string, int> $labels */
            public function __construct(private readonly array $labels) {}

            public function definition(string $labelId): ?ClassificationLabelDefinition
            {
                if (!array_key_exists($labelId, $this->labels)) {
                    return null;
                }

                return new ClassificationLabelDefinition([
                    'label_id' => $labelId,
                    'display_name' => ucfirst($labelId),
                    'confidentiality_level' => $this->labels[$labelId],
                ]);
            }

            public function invalidate(): void
            {
                // No-op for tests.
            }
        };
    }

    /**
     * @param array<string, int> $roleLevels
     */
    private function clearanceChecker(array $roleLevels): ClassificationClearanceCheckerInterface
    {
        return new class($roleLevels) implements ClassificationClearanceCheckerInterface {
            /** @param array<string, int> $roleLevels */
            public function __construct(private readonly array $roleLevels) {}

            public function clearanceLevelFor(AccountInterface $account): int
            {
                $best = 0;
                foreach ($account->getRoles() as $role) {
                    $level = $this->roleLevels[$role] ?? 0;
                    if ($level > $best) {
                        $best = $level;
                    }
                }

                return $best;
            }
        };
    }

    /**
     * @param list<string>            $roles
     * @param array<string, true>     $permissions
     */
    private function account(array $roles, array $permissions = []): AccountInterface
    {
        return new class($roles, $permissions) implements AccountInterface {
            /**
             * @param list<string>        $roles
             * @param array<string, true> $permissions
             */
            public function __construct(
                private readonly array $roles,
                private readonly array $permissions,
            ) {}

            public function id(): int
            {
                return $this->roles === [] ? 0 : 1;
            }

            public function hasPermission(string $permission): bool
            {
                return isset($this->permissions[$permission]);
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return $this->roles === [] ? ['anonymous'] : $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return $this->roles !== [];
            }
        };
    }

    private function entity(?string $labelId): EntityInterface
    {
        $values = [];
        if ($labelId !== null) {
            $values['classification_label'] = $labelId;
        }

        return new class($values) extends ContentEntityBase {
            /**
             * @param array<string, mixed>  $values
             * @param array<string, string> $entityKeys
             * @param array<string, mixed>  $fieldDefinitions
             */
            public function __construct(
                array $values = [],
                string $entityTypeId = 'test_subject',
                array $entityKeys = ['id' => 'id', 'uuid' => 'uuid'],
                array $fieldDefinitions = [],
            ) {
                parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
            }
        };
    }
}
