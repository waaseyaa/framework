<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\StreamChunk;

#[CoversClass(StreamChunk::class)]
final class StreamChunkTest extends TestCase
{
    public function testTextDelta(): void
    {
        $chunk = new StreamChunk(type: 'text_delta', text: 'Hello');
        $this->assertSame('text_delta', $chunk->type);
        $this->assertSame('Hello', $chunk->text);
        $this->assertNull($chunk->toolUse);
    }

    public function testToolUseChunk(): void
    {
        $toolUse = new \Waaseyaa\AI\Agent\Provider\ToolUseBlock(id: 'tu_1', name: 'read', input: []);
        $chunk = new StreamChunk(type: 'tool_use_start', toolUse: $toolUse);
        $this->assertSame('tool_use_start', $chunk->type);
        $this->assertNotNull($chunk->toolUse);
        $this->assertSame('read', $chunk->toolUse->name);
    }
}
