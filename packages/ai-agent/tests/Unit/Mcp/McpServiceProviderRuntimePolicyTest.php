<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Mcp\McpCapabilitiesSource;
use Waaseyaa\AI\Agent\Mcp\McpServiceProvider;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;

#[CoversClass(McpServiceProvider::class)]
final class McpServiceProviderRuntimePolicyTest extends TestCase
{
    #[Test]
    public function missingProfileCannotInheritProcessDevelopmentForNullConfiguration(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=local');

        try {
            $provider = new McpServiceProvider();
            $provider->setKernelContext(dirname(__DIR__, 5), [], []);
            $provider->register();

            $provider->resolve(McpCapabilitiesSource::class);
            self::fail('A missing explicit profile must not enable NullConfigStorage.');
        } catch (ConfigurationAuthorityUnavailableException $e) {
            self::assertStringContainsString('NullConfigStorage is permitted only', $e->getMessage());
        } finally {
            $previous === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV=' . $previous);
        }
    }
}
