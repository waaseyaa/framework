<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Routing\Attribute\GateAttribute;
use Waaseyaa\Routing\RouteBuilder;

/**
 * Tests that RouteBuilder::gate() correctly wires the _gate option so that
 * AccessChecker enforces the ability at request time.
 *
 * Failing-first: these tests fail RED before RouteBuilder::gate() is added
 * (PHP fatal "Call to undefined method RouteBuilder::gate()").
 */
#[CoversClass(RouteBuilder::class)]
#[CoversClass(GateAttribute::class)]
final class RouteBuilderGateTest extends TestCase
{
    #[Test]
    public function routeBuilderGateSetsGateOptionShape(): void
    {
        $route = RouteBuilder::create('/api/config/export')
            ->gate('config.export')
            ->build();

        $gateOption = $route->getOption('_gate');
        $this->assertIsArray($gateOption);
        $this->assertSame('config.export', $gateOption['ability']);
        $this->assertNull($gateOption['subject']);
    }

    #[Test]
    public function routeBuilderGateSetsGateOptionShapeWithSubject(): void
    {
        $route = RouteBuilder::create('/api/node/{id}')
            ->gate('node.update', 'node')
            ->build();

        $gateOption = $route->getOption('_gate');
        $this->assertIsArray($gateOption);
        $this->assertSame('node.update', $gateOption['ability']);
        $this->assertSame('node', $gateOption['subject']);
    }

    #[Test]
    public function routeBuilderGateIsFluentAndChainable(): void
    {
        $route = RouteBuilder::create('/api/config/export')
            ->controller('App\Controller\ConfigController::export')
            ->gate('config.export')
            ->methods('GET')
            ->build();

        $gateOption = $route->getOption('_gate');
        $this->assertSame('config.export', $gateOption['ability']);
        $this->assertSame(['GET'], $route->getMethods());
    }

    #[Test]
    public function routeBuilderGateEnforcedByAccessCheckerDeniesWithoutAbility(): void
    {
        // Account that does NOT have the gate ability.
        $account = $this->createMock(AccountInterface::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(false);

        $checker = new AccessChecker(gate: $gate);

        $route = RouteBuilder::create('/api/config/export')
            ->gate('config.export')
            ->build();

        $result = $checker->check($route, $account);

        $this->assertTrue(
            $result->isForbidden(),
            'AccessChecker must deny access when the gate denies the ability',
        );
    }

    #[Test]
    public function routeBuilderGateEnforcedByAccessCheckerAllowsWithAbility(): void
    {
        // Account that DOES have the gate ability.
        $account = $this->createMock(AccountInterface::class);

        $gate = $this->createMock(GateInterface::class);
        $gate->method('allows')
            ->with('config.export', null, $account)
            ->willReturn(true);

        $checker = new AccessChecker(gate: $gate);

        $route = RouteBuilder::create('/api/config/export')
            ->gate('config.export')
            ->build();

        $result = $checker->check($route, $account);

        $this->assertTrue(
            $result->isAllowed(),
            'AccessChecker must allow access when the gate grants the ability',
        );
    }

    #[Test]
    public function gateAttributeIsStillAValidValueObjectAfterDocblockCorrection(): void
    {
        // The attribute must still construct and hold its values correctly —
        // correcting + deprecating the docblock must not change the runtime behavior.
        $attr = new GateAttribute('config.export', 'entity_type');

        $this->assertSame('config.export', $attr->ability);
        $this->assertSame('entity_type', $attr->subject);
    }
}
