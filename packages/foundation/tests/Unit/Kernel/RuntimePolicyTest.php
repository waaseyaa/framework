<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;

#[CoversClass(RuntimePolicy::class)]
final class RuntimePolicyTest extends TestCase
{
    #[Test]
    public function configuredEnvironmentAndProcessDebugUseKernelPrecedence(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=1');

        try {
            $policy = RuntimePolicy::resolve([
                'environment' => 'staging',
                'debug' => false,
            ]);

            self::assertSame('staging', $policy->environment);
            self::assertTrue($policy->debug);
        } finally {
            putenv('APP_ENV');
            putenv('APP_DEBUG');
        }
    }

    #[Test]
    public function processEnvironmentAndConfiguredDebugAreFallbacks(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG');

        try {
            $policy = RuntimePolicy::resolve(['debug' => true]);

            self::assertSame('local', $policy->environment);
            self::assertTrue($policy->debug);
        } finally {
            putenv('APP_ENV');
        }
    }

    #[Test]
    public function absentOrInvalidValuesFailToProductionAndDebugOff(): void
    {
        putenv('APP_ENV');
        putenv('APP_DEBUG');

        $policy = RuntimePolicy::resolve([
            'environment' => null,
            'debug' => 'not-a-boolean',
        ]);

        self::assertSame('production', $policy->environment);
        self::assertFalse($policy->debug);
    }
}
