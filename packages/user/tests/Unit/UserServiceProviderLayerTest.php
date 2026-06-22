<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\UserServiceProvider;

/**
 * The user package sits at Layer 1; SSR sits at Layer 6. UserServiceProvider
 * used to obtain a Twig environment by statically calling
 * `Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment()` — an upward layer
 * violation (and an undeclarable dependency) that is invisible to the
 * `use`-statement-based layer gate because it was written as an inline FQN.
 *
 * The Twig renderer is now resolved from the container, so the source must not
 * mention the SSR namespace at all.
 */
#[CoversClass(UserServiceProvider::class)]
final class UserServiceProviderLayerTest extends TestCase
{
    #[Test]
    public function does_not_reach_into_the_ssr_layer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/UserServiceProvider.php',
        );

        self::assertStringNotContainsString(
            'Waaseyaa\\SSR',
            $source,
            'UserServiceProvider (Layer 1) must not reference the Layer-6 SSR package.',
        );
    }
}
