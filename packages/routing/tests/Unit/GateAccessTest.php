<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Routing\Attribute\GateAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

#[CoversClass(AccessChecker::class)]
#[CoversClass(GateAttribute::class)]
final class GateAccessTest extends TestCase
{
    // --- GateAttribute ---

    #[Test]
    public function gateAttributeStoresAbility(): void
    {
        $attr = new GateAttribute('config.export');

        $this->assertSame('config.export', $attr->ability);
        $this->assertNull($attr->subject);
    }

    #[Test]
    public function gateAttributeStoresAbilityAndSubject(): void
    {
        $attr = new GateAttribute('node.update', 'node');

        $this->assertSame('node.update', $attr->ability);
        $this->assertSame('node', $attr->subject);
    }

    #[Test]
    public function gateAttributeIsRepeatable(): void
    {
        $reflection = new \ReflectionClass(GateAttribute::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        $this->assertCount(1, $attributes);

        $attrInstance = $attributes[0]->newInstance();
        $this->assertTrue(($attrInstance->flags & \Attribute::IS_REPEATABLE) !== 0);
    }

    #[Test]
    public function gateAttributeCanTargetMethodAndClass(): void
    {
        $reflection = new \ReflectionClass(GateAttribute::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        $attrInstance = $attributes[0]->newInstance();

        $this->assertTrue(($attrInstance->flags & \Attribute::TARGET_METHOD) !== 0);
        $this->assertTrue(($attrInstance->flags & \Attribute::TARGET_CLASS) !== 0);
    }

    // --- applyGateToRoute ---

    #[Test]
    public function applyGateToRouteSetsGateOption(): void
    {
        $route = new Route('/api/config/export');

        AccessChecker::applyGateToRoute($route, 'config.export');

        $gateOption = $route->getOption('_gate');
        $this->assertIsArray($gateOption);
        $this->assertSame('config.export', $gateOption['ability']);
        $this->assertNull($gateOption['subject']);
    }

    #[Test]
    public function applyGateToRouteSetsGateOptionWithSubject(): void
    {
        $route = new Route('/api/node/{id}');

        AccessChecker::applyGateToRoute($route, 'node.update', 'node');

        $gateOption = $route->getOption('_gate');
        $this->assertSame('node.update', $gateOption['ability']);
        $this->assertSame('node', $gateOption['subject']);
    }

    // --- Gate check via AccessChecker ---

    #[Test]
    public function gateAllowsReturnsAllowed(): void
    {
        $account = $this->createMock(AccountInterface::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(true);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $result = $checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function gateDeniesReturnsForbidden(): void
    {
        $account = $this->createMock(AccountInterface::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(false);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $result = $checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function gateRequiredButNoGateAvailableReturnsForbidden(): void
    {
        // No gate provided to the AccessChecker.
        $checker = new AccessChecker();

        $route = new Route('/api/config/export');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $account = $this->createMock(AccountInterface::class);
        $result = $checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function gateCombinedWithPermissionBothPassReturnsAllowed(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('access config')
            ->willReturn(true);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(true);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        $route->setOption('_permission', 'access config');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $result = $checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function gateCombinedWithPermissionGateFailsReturnsForbidden(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('access config')
            ->willReturn(true);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(false);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        $route->setOption('_permission', 'access config');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $result = $checker->check($route, $account);

        // AND logic: permission allowed AND gate forbidden => forbidden.
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function gateCombinedWithPermissionPermissionFailsReturnsForbidden(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('access config')
            ->willReturn(false);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(true);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        $route->setOption('_permission', 'access config');
        AccessChecker::applyGateToRoute($route, 'config.export');

        $result = $checker->check($route, $account);

        // AND logic: permission forbidden AND gate allowed => forbidden.
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function noGateOptionDoesNotTriggerGateCheck(): void
    {
        $gate = $this->createMock(GateInterface::class);
        $gate->expects($this->never())->method('allows');

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/test');
        $route->setOption('_public', true);

        $account = $this->createMock(AccountInterface::class);
        $result = $checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function gateWithEmptyAbilityReturnsForbidden(): void
    {
        $gate = $this->createMock(GateInterface::class);
        $gate->expects($this->never())->method('allows');

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/config/export');
        $route->setOption('_gate', ['ability' => '', 'subject' => null]);

        $account = $this->createMock(AccountInterface::class);
        $result = $checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function gatePassesSubjectToGateCheck(): void
    {
        $account = $this->createMock(AccountInterface::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->expects($this->once())
            ->method('allows')
            ->with('node.update', 'node', $account)
            ->willReturn(true);

        $checker = new AccessChecker(gate: $gate);

        $route = new Route('/api/node/{id}');
        AccessChecker::applyGateToRoute($route, 'node.update', 'node');

        $result = $checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }
}
