<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Entity\FieldReadLevel;

final class FieldReadGuardTest extends TestCase
{
    public function test_dormant_guard_preserves_existing_reads(): void
    {
        $guard = $this->guard(new AccountFieldReadScope(), false);
        $rule = new CompiledFieldReadRule('mail', FieldReadLevel::Internal);
        $guard->assertCompiled($this->entity(), $rule);

        self::addToAssertionCount(1);
    }

    public function test_activated_protected_read_requires_context_and_explicit_allow(): void
    {
        $scope = new AccountFieldReadScope();
        $guard = $this->guard($scope, true);
        $entity = $this->entity();
        $rule = new CompiledFieldReadRule('name', FieldReadLevel::Protected);

        try {
            $guard->assertCompiled($entity, $rule);
            self::fail('Missing context was accepted.');
        } catch (MissingFieldReadContext) {
        }

        $principal = new AuthorizationPrincipal(7, true, [], [], 'claims-1');
        $scope->run($principal, static fn() => $guard->assertCompiled($entity, $rule));
        self::addToAssertionCount(1);
    }

    public function test_activated_internal_read_requires_audited_reader(): void
    {
        $this->expectException(FieldReadDenied::class);
        $this->guard(new AccountFieldReadScope(), true)->assertCompiled(
            $this->entity(),
            new CompiledFieldReadRule('mail', FieldReadLevel::Internal),
        );
    }

    public function test_warm_decision_is_context_bound_and_explicitly_invalidated_after_mutation(): void
    {
        $scope = new AccountFieldReadScope();
        $calls = 0;
        $allow = true;
        $guard = new FieldReadGuard(
            $scope,
            static function () use (&$calls, &$allow): AccessResult {
                ++$calls;

                return $allow ? AccessResult::allowed() : AccessResult::forbidden();
            },
            activationEnabled: true,
        );
        $entity = $this->entity();
        $rule = new CompiledFieldReadRule('name', FieldReadLevel::Protected);
        $principal = new AuthorizationPrincipal(7, true, [], [], 'claims-1');

        $scope->run($principal, function () use ($guard, $entity, $rule, &$allow): void {
            $guard->assertCompiled($entity, $rule);
            $guard->assertCompiled($entity, $rule);
            $allow = false;
            $guard->invalidate($entity);
            try {
                $guard->assertCompiled($entity, $rule);
                self::fail('An invalidated decision was reused.');
            } catch (FieldReadDenied) {
            }
        });

        self::assertSame(2, $calls);

        $allow = true;
        $scope->run($principal, static fn() => $guard->assertCompiled($entity, $rule));
        self::assertSame(3, $calls, 'A later scope frame must not inherit the prior frame cache.');
    }

    public function test_nested_scope_cannot_reuse_the_parent_decision_cache(): void
    {
        $scope = new AccountFieldReadScope();
        $calls = 0;
        $guard = new FieldReadGuard(
            $scope,
            static function (AuthorizationPrincipalInterface $principal) use (&$calls): AccessResult {
                ++$calls;

                return $principal->id() === 7 ? AccessResult::allowed() : AccessResult::forbidden();
            },
            activationEnabled: true,
        );
        $entity = $this->entity();
        $rule = new CompiledFieldReadRule('name', FieldReadLevel::Protected);
        $outer = new AuthorizationPrincipal(7, true, [], [], 'outer');
        $inner = new AuthorizationPrincipal(8, true, [], [], 'inner');

        $scope->runWithGenerations($outer, 'class-1', 'policy-1', function () use ($scope, $inner, $guard, $entity, $rule): void {
            $guard->assertCompiled($entity, $rule);
            $scope->runWithGenerations($inner, 'class-1', 'policy-1', function () use ($guard, $entity, $rule): void {
                try {
                    $guard->assertCompiled($entity, $rule);
                    self::fail('A nested principal reused its parent decision.');
                } catch (FieldReadDenied) {
                }
            });
            $guard->assertCompiled($entity, $rule);
        });

        self::assertSame(2, $calls);
    }

    public function test_ended_scope_does_not_retain_its_last_read_entity(): void
    {
        $scope = new AccountFieldReadScope();
        $guard = $this->guard($scope, true);
        $rule = new CompiledFieldReadRule('name', FieldReadLevel::Protected);
        $principal = new AuthorizationPrincipal(7, true, [], [], 'claims-1');
        $weak = (function () use ($scope, $guard, $rule, $principal): \WeakReference {
            $entity = $this->entity();
            $weak = \WeakReference::create($entity);
            $scope->runWithGenerations($principal, 'class-1', 'policy-1', static fn() => $guard->assertCompiled($entity, $rule));

            return $weak;
        })();

        gc_collect_cycles();
        self::assertNull($weak->get(), 'A completed worker scope must release its subject graph.');
    }

    private function guard(AccountFieldReadScope $scope, bool $active): FieldReadGuard
    {
        return new FieldReadGuard(
            $scope,
            static fn() => AccessResult::allowed(),
            $active,
        );
    }

    private function entity(): EntityBase
    {
        return new class (['mail' => 'member@example.test', 'name' => 'member']) extends EntityBase {
            public function __construct(array $values)
            {
                parent::__construct($values, 'user', ['id' => 'uid', 'label' => 'name']);
            }
        };
    }
}
