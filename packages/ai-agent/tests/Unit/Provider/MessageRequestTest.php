<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\MessageRequest;

#[CoversClass(MessageRequest::class)]
final class MessageRequestTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hello']],
        );

        $this->assertSame([['role' => 'user', 'content' => 'Hello']], $request->messages);
        $this->assertNull($request->system);
        $this->assertSame([], $request->tools);
        $this->assertSame(4096, $request->maxTokens);
    }

    public function testToArray(): void
    {
        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            system: 'You are helpful.',
            maxTokens: 1024,
        );

        $array = $request->toArray();
        $this->assertSame('You are helpful.', $array['system']);
        $this->assertSame(1024, $array['max_tokens']);
        $this->assertArrayNotHasKey('tools', $array);
    }
}
