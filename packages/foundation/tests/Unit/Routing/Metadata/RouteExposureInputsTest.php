<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Routing\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteExposureInputs;

#[CoversClass(RouteExposureInputs::class)]
final class RouteExposureInputsTest extends TestCase
{
    public function testPublicationDetachesAndFreezeRequiresExactRoster(): void
    {
        $inputs = new RouteExposureInputs();
        $exposed = true;
        $inputs->publish(['post' => &$exposed, 'private' => false]);
        $exposed = false;
        $frozen = $inputs->freeze(['private', 'post'], true);
        self::assertSame(['post' => true, 'private' => false], $frozen);
        $inputs->assertReady();
        $frozen['post'] = false;
        $inputs->assertReady();
    }

    public function testAdmittedApiAbsenceYieldsFalseForEveryEntity(): void
    {
        self::assertSame(['post' => false], new RouteExposureInputs()->freeze(['post'], false));
    }

    public function testMissingDuplicateMalformedStaleAndUnexpectedPublicationRefuse(): void
    {
        foreach (['missing', 'duplicate', 'malformed', 'stale', 'unexpected'] as $case) {
            $inputs = new RouteExposureInputs();
            if ($case !== 'missing') {
                $inputs->publish(['post' => $case === 'malformed' ? 'private-value' : true]);
            }
            if ($case === 'duplicate') {
                $inputs->publish(['post' => true]);
            }
            try {
                $inputs->freeze($case === 'stale' ? ['other'] : ['post'], $case !== 'unexpected');
                self::fail('Invalid boot handoff must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inputs-unavailable', $error->reason);
                self::assertStringNotContainsString('private-value', $error->getMessage());
            }
            try {
                $inputs->assertReady();
                self::fail('Failed handoff remains terminal.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inputs-unavailable', $error->reason);
            }
        }
    }

    public function testLatePublicationPoisonsAccessWithoutChangingReturnedData(): void
    {
        $inputs = new RouteExposureInputs();
        $inputs->publish(['post' => true]);
        $frozen = $inputs->freeze(['post'], true);
        try {
            $inputs->publish(['post' => false]);
            self::fail('Late publication must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inputs-unavailable', $error->reason);
        }
        self::assertSame(['post' => true], $frozen);
        $this->expectException(RouteCompositionException::class);
        $inputs->assertReady();
    }

    public function testNumericLikeRosterKeysCompareAsStrings(): void
    {
        $inputs = new RouteExposureInputs();
        $inputs->publish(['1e0' => false, '01' => true]);
        self::assertSame(['1e0' => false, '01' => true], $inputs->freeze(['01', '1e0'], true));
    }
    public function testCapabilitiesDetachAndBecomeReadableOnlyAfterFreeze(): void
    {
        $inputs = new RouteExposureInputs();
        $available = true;
        $inputs->publish(['post' => true], ['api.route.catalog' => &$available]);
        $available = false;
        try {
            $inputs->routeCapabilities();
            self::fail('Unfrozen capabilities must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inputs-unavailable', $error->reason);
        }
        $inputs->freeze(['post'], true);
        $copy = $inputs->routeCapabilities();
        self::assertSame(['api.route.catalog' => true], $copy);
        $copy['api.route.catalog'] = false;
        self::assertSame(['api.route.catalog' => true], $inputs->routeCapabilities());
    }

    public function testMalformedCapabilitiesPoisonBothPublications(): void
    {
        foreach ([['api' => true], ['service:secret' => true], ['api.route.' => true], [0 => true], ['api.route.catalog' => 'private-value']] as $capabilities) {
            $inputs = new RouteExposureInputs();
            $inputs->publish(['post' => true], $capabilities);
            foreach (['freeze', 'routeCapabilities'] as $operation) {
                try {
                    $operation === 'freeze' ? $inputs->freeze(['post'], true) : $inputs->routeCapabilities();
                    self::fail('Malformed publication must refuse.');
                } catch (RouteCompositionException $error) {
                    self::assertSame('inputs-unavailable', $error->reason);
                    self::assertStringNotContainsString('private-value', $error->getMessage());
                }
            }
        }
    }

    public function testLateAndDuplicatePublicationPoisonCapabilities(): void
    {
        foreach ([false, true] as $late) {
            $inputs = new RouteExposureInputs();
            $inputs->publish(['post' => true], ['api.route.catalog' => true]);
            $copy = [];
            if ($late) {
                $inputs->freeze(['post'], true);
                $copy = $inputs->routeCapabilities();
            }
            try {
                $inputs->publish(['post' => true], ['api.route.catalog' => false]);
            } catch (RouteCompositionException) {
                self::assertTrue($late);
            }
            try {
                $inputs->routeCapabilities();
                self::fail('Poisoned capabilities must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inputs-unavailable', $error->reason);
            }
            if ($late) {
                self::assertSame(['api.route.catalog' => true], $copy);
            }
        }
        $absent = new RouteExposureInputs();
        $absent->freeze([], false);
        self::assertSame([], $absent->routeCapabilities());
    }

}
