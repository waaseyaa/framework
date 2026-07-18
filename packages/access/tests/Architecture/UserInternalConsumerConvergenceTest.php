<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Prevents framework User-internal consumers from bypassing closed audited seams. */
final class UserInternalConsumerConvergenceTest extends TestCase
{
    public function test_framework_consumers_have_no_ordinary_internal_accessor_or_query(): void
    {
        $root = dirname(__DIR__, 4);
        $files = [
            'packages/auth/src',
            'packages/user/src/AuthMailer.php',
            'packages/user/src/Http',
            'packages/api/src/Controller/NotificationController.php',
            'packages/cli/src/Handler/UserRoleHandler.php',
            'packages/cli/src/Handler/UserAssignRoleHandler.php',
        ];
        $violations = [];
        $forbidden = [
            '/->(?:getEmail|getPasswordHash|isEmailVerified|isActive|checkPassword|getTwoFactorSecret|getTwoFactorRecoveryCodesHash|getTwoFactorLastUsedStep)\s*\(/',
            '/->get\(\s*[\'\"](?:mail|pass|roles|permissions|email_verified|two_factor_[^\'\"]+)[\'\"]\s*\)/',
            '/->condition\(\s*[\'\"](?:mail|status|roles|permissions|email_verified|two_factor_[^\'\"]+)[\'\"]/',
        ];

        foreach ($files as $relative) {
            $path = $root.'/'.$relative;
            $candidates = is_dir($path)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path))
                : [new \SplFileInfo($path)];
            foreach ($candidates as $candidate) {
                if (!$candidate instanceof \SplFileInfo || !$candidate->isFile() || $candidate->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($candidate->getPathname());
                self::assertIsString($source);
                foreach ($forbidden as $pattern) {
                    if (preg_match($pattern, $source) === 1) {
                        $violations[] = str_replace($root.'/', '', $candidate->getPathname()).' matches '.$pattern;
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
