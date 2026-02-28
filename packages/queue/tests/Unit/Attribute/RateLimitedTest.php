<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit\Attribute;

use Aurora\Queue\Attribute\RateLimited;
use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimited::class)]
final class RateLimitedTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $attr = new RateLimited();

        $this->assertSame(1, $attr->maxAttempts);
        $this->assertSame(60, $attr->decaySeconds);
    }

    #[Test]
    public function customValues(): void
    {
        $attr = new RateLimited(maxAttempts: 10, decaySeconds: 300);

        $this->assertSame(10, $attr->maxAttempts);
        $this->assertSame(300, $attr->decaySeconds);
    }

    #[Test]
    public function canBeAppliedToJobClass(): void
    {
        $jobClass = new #[RateLimited(maxAttempts: 5, decaySeconds: 120)] class extends Job {
            public function handle(): void {}
        };

        $reflection = new \ReflectionClass($jobClass);
        $attributes = $reflection->getAttributes(RateLimited::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame(5, $instance->maxAttempts);
        $this->assertSame(120, $instance->decaySeconds);
    }

    #[Test]
    public function isTargetedAtClasses(): void
    {
        $reflection = new \ReflectionClass(RateLimited::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);
        $attrInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attrInstance->flags);
    }
}
