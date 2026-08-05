<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;

final class McpPrincipalContractTest extends TestCase
{
    #[Test]
    public function auth_and_tool_execution_share_the_immutable_principal_contract(): void
    {
        $authReturn = (new \ReflectionMethod(McpAuthInterface::class, 'authenticate'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $authReturn);
        self::assertSame(AuthorizationPrincipalInterface::class, $authReturn->getName());
        self::assertTrue($authReturn->allowsNull());

        foreach (['execute', 'dryRun'] as $method) {
            $actor = (new \ReflectionMethod(AgentToolInterface::class, $method))->getParameters()[1]->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $actor);
            self::assertSame(AuthorizationPrincipalInterface::class, $actor->getName());
        }
    }
}
