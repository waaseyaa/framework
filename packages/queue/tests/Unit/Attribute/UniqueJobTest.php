<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit\Attribute;

use Aurora\Queue\Attribute\UniqueJob;
use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UniqueJob::class)]
final class UniqueJobTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $attr = new UniqueJob();

        $this->assertSame(3600, $attr->lockSeconds);
        $this->assertSame('', $attr->key);
    }

    #[Test]
    public function customValues(): void
    {
        $attr = new UniqueJob(lockSeconds: 120, key: 'my-unique-key');

        $this->assertSame(120, $attr->lockSeconds);
        $this->assertSame('my-unique-key', $attr->key);
    }

    #[Test]
    public function canBeAppliedToJobClass(): void
    {
        $jobClass = new #[UniqueJob(lockSeconds: 60, key: 'test')] class extends Job {
            public function handle(): void {}
        };

        $reflection = new \ReflectionClass($jobClass);
        $attributes = $reflection->getAttributes(UniqueJob::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame(60, $instance->lockSeconds);
        $this->assertSame('test', $instance->key);
    }

    #[Test]
    public function isTargetedAtClasses(): void
    {
        $reflection = new \ReflectionClass(UniqueJob::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);
        $attrInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attrInstance->flags);
    }
}
