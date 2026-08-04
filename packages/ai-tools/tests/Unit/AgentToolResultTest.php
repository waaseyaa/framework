<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentToolResult;

#[CoversClass(AgentToolResult::class)]
final class AgentToolResultTest extends TestCase
{
    #[Test]
    public function success_factory_marks_result_as_not_error(): void
    {
        $result = AgentToolResult::success(
            content: [['type' => 'text', 'text' => 'ok']],
            summary: 'done',
            structuredContent: ['ok' => true],
        );

        self::assertFalse($result->isError);
        self::assertSame('done', $result->summary);
        self::assertSame([['type' => 'text', 'text' => 'ok']], $result->content);
        self::assertSame(['ok' => true], $result->structuredContent);
    }

    #[Test]
    public function error_factory_marks_result_as_error_and_wraps_message(): void
    {
        $result = AgentToolResult::error('boom');

        self::assertTrue($result->isError);
        self::assertSame('boom', $result->summary);
        self::assertSame([['type' => 'text', 'text' => 'boom']], $result->content);
        self::assertNull($result->structuredContent);
    }
}
