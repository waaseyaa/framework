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
        self::assertFalse($policy->isDevelopment());
        self::assertTrue($policy->isProductionLike());
    }

    #[Test]
    public function environmentClassificationUsesAnExplicitNormalizedDevelopmentAllowlist(): void
    {
        foreach ([
            ['local', true],
            [' LOCAL ', true],
            ['dev', true],
            ['Development', true],
            ['testing', true],
            ['production', false],
            ['staging', false],
            ['unknown', false],
            ['', false],
            ['0', false],
        ] as [$environment, $expectedDevelopment]) {
            $policy = new RuntimePolicy($environment, false);

            self::assertSame($expectedDevelopment, $policy->isDevelopment(), $environment);
            self::assertSame(!$expectedDevelopment, $policy->isProductionLike(), $environment);
        }
    }

    #[Test]
    public function canonicalClassifierIsAvailableToFoundationDependentPolicies(): void
    {
        foreach ([
            ['local', true],
            [' LOCAL ', true],
            ['dev', true],
            ['Development', true],
            ['testing', true],
            ['production', false],
            ['staging', false],
            ['unknown', false],
            ['', false],
        ] as [$environment, $expectedDevelopment]) {
            self::assertSame(
                $expectedDevelopment,
                RuntimePolicy::isDevelopmentEnvironment($environment),
                $environment,
            );
        }
    }

    #[Test]
    public function explicitClassifierNeverInheritsTheProcessEnvironment(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=local');

        try {
            self::assertFalse(RuntimePolicy::isExplicitDevelopment([]));
            self::assertFalse(RuntimePolicy::isExplicitDevelopment(['environment' => null]));
            self::assertFalse(RuntimePolicy::isExplicitDevelopment(['environment' => '']));
            self::assertTrue(RuntimePolicy::isExplicitDevelopment(['environment' => ' Testing ']));
        } finally {
            $previous === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV=' . $previous);
        }
    }
}
