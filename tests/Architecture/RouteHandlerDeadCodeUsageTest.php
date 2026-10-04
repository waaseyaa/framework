<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tools\PHPStan\WaaseyaaEntrypointProvider;

// Repository tooling lives outside PHPUnit's packages/*/src product coverage scope.
#[CoversNothing]
final class RouteHandlerDeadCodeUsageTest extends TestCase
{
    #[Test]
    public function finite_metadata_targets_keep_their_caller_edge(): void
    {
        $node = $this->call();
        $scope = $this->scope(TypeCombinator::union(
            new ConstantStringType('class:Example\\Controller::show'),
            new ConstantStringType('service:Example\\OtherController::save'),
            new ConstantStringType('builtin:json_api'),
        ));
        $usages = new WaaseyaaEntrypointProvider(__DIR__ . '/missing-project')->getUsages($node, $scope);
        self::assertCount(1, $usages);
        $targets = [];
        foreach ($usages as $usage) {
            $targets[] = $usage->getMemberRef()->getClassName() . '::' . $usage->getMemberRef()->getMemberName();
            self::assertSame('metadata-fixture.php', $usage->getOrigin()->getFile());
            self::assertNull($usage->getOrigin()->getNote(), 'Declarations must retain regular call edges, not global virtual entrypoints.');
        }
        sort($targets);
        self::assertSame(['Example\\Controller::show'], $targets);
    }

    #[Test]
    public function unknown_strings_and_malformed_targets_do_not_keep_arbitrary_methods(): void
    {
        $provider = new WaaseyaaEntrypointProvider(__DIR__ . '/missing-project');
        self::assertSame([], $provider->getUsages($this->call(), $this->scope(new StringType())));
        foreach (['class:Example\\Controller::*', 'class:Example\\Controller', 'class:Example\\Controller::show!', 'service:Example\\Controller::show'] as $identifier) {
            self::assertSame([], $provider->getUsages($this->call(), $this->scope(new ConstantStringType($identifier))));
        }
    }

    #[Test]
    public function unrelated_factory_calls_do_not_declare_route_handlers(): void
    {
        $scope = $this->createMock(Scope::class);
        $scope->method('resolveName')->willReturn('Example\\OtherFactory');
        $scope->expects(self::never())->method('getType');
        self::assertSame([], new WaaseyaaEntrypointProvider(__DIR__ . '/missing-project')->getUsages($this->call(), $scope));
    }

    private function call(): Node\Expr\StaticCall
    {
        return new Node\Expr\StaticCall(
            new Node\Name('HandlerReference'),
            'fromString',
            [new Node\Arg(new Node\Expr\Variable('identifier'))],
        );
    }

    private function scope(Type $type): Scope
    {
        $scope = $this->createStub(Scope::class);
        $scope->method('resolveName')->willReturn('Waaseyaa\\Foundation\\Routing\\Metadata\\HandlerReference');
        $scope->method('getType')->willReturn($type);
        $scope->method('getFile')->willReturn('metadata-fixture.php');
        $scope->method('isInTrait')->willReturn(false);
        $scope->method('isInClass')->willReturn(false);
        return $scope;
    }
}
