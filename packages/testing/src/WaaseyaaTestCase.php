<?php

declare(strict_types=1);

namespace Waaseyaa\Testing;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Traits\CreatesApplication;
use Waaseyaa\Testing\Traits\InteractsWithAuth;
use Waaseyaa\Testing\Traits\InteractsWithEvents;

/**
 * Base test case for Waaseyaa tests.
 *
 * Provides application bootstrapping, authentication helpers,
 * event interaction, and common assertion methods. Extend this
 * class instead of PHPUnit\Framework\TestCase for tests that
 * need the Waaseyaa application environment.
 */
abstract class WaaseyaaTestCase extends TestCase
{
    use CreatesApplication;
    use InteractsWithAuth;
    use InteractsWithEvents;

    /**
     * Whether the application has been booted for this test.
     */
    private bool $appBooted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootApplication();
        $this->appBooted = true;
    }

    protected function tearDown(): void
    {
        if ($this->appBooted) {
            $this->shutdownApplication();
            $this->appBooted = false;
        }

        $this->resetAuth();
        $this->resetEvents();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Common assertion helpers
    // -----------------------------------------------------------------

    /**
     * Assert that an array has all of the given keys.
     *
     * @param string[] $keys
     * @param array<string, mixed> $array
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Missing key '{$key}'.");
        }
    }

    /**
     * Assert that a value is a non-empty string.
     */
    protected function assertNonEmptyString(mixed $value, string $message = ''): void
    {
        $this->assertIsString($value, $message ?: 'Expected a string.');
        $this->assertNotEmpty($value, $message ?: 'Expected a non-empty string.');
    }

    /**
     * Assert that two arrays have the same values regardless of order.
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    protected function assertSameValues(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $message ?: 'Array values do not match.');
    }

    /**
     * Assert that a string matches a given regular expression pattern.
     */
    protected function assertStringMatchesPattern(string $pattern, string $string, string $message = ''): void
    {
        $this->assertMatchesRegularExpression($pattern, $string, $message);
    }
}
