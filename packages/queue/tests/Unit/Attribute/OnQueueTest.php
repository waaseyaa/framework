<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit\Attribute;

use Aurora\Queue\Attribute\OnQueue;
use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OnQueue::class)]
final class OnQueueTest extends TestCase
{
    #[Test]
    public function storesQueueName(): void
    {
        $attr = new OnQueue(name: 'high-priority');

        $this->assertSame('high-priority', $attr->name);
    }

    #[Test]
    public function canBeAppliedToJobClass(): void
    {
        $jobClass = new #[OnQueue(name: 'emails')] class extends Job {
            public function handle(): void {}
        };

        $reflection = new \ReflectionClass($jobClass);
        $attributes = $reflection->getAttributes(OnQueue::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('emails', $instance->name);
    }

    #[Test]
    public function isTargetedAtClasses(): void
    {
        $reflection = new \ReflectionClass(OnQueue::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);
        $attrInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attrInstance->flags);
    }
}
