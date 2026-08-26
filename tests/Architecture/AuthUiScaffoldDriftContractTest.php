<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;

#[CoversNothing]
final class AuthUiScaffoldDriftContractTest extends TestCase
{
    #[Test]
    public function thePublishableSetContainsPresentationFilesOnly(): void
    {
        self::assertSame([
            'pages/login.vue' => 'pages/login.vue',
            'components/auth/LoginForm.vue' => 'components/auth/LoginForm.vue',
            'components/auth/BrandPanel.vue' => 'components/auth/BrandPanel.vue',
            'composables/useAuth.ts' => 'composables/useAuth.ts',
            'assets/auth.css' => 'assets/auth.css',
        ], AuthUiScaffoldManager::FILE_MAP);

        foreach (array_merge(array_keys(AuthUiScaffoldManager::FILE_MAP), array_values(AuthUiScaffoldManager::FILE_MAP)) as $path) {
            self::assertMatchesRegularExpression('#^(pages|components|composables|assets)/#', $path);
            self::assertDoesNotMatchRegularExpression(
                '#(?:Controller|Middleware|Credential|Session|Csrf|Token|RateLimit|TwoFactor|2FA)#i',
                $path,
            );
        }
    }

    #[Test]
    public function theSiteAuditSurfacesDriftWithoutMakingItBlocking(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../skeleton/bin/maintenance/waaseyaa-audit-site');

        self::assertStringContainsString('scaffold:auth --check', $script);
        self::assertStringContainsString('if ! ./vendor/bin/waaseyaa scaffold:auth --check; then', $script);
    }
}
