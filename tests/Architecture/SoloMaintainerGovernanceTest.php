<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SoloMaintainerGovernanceTest extends TestCase
{
    #[Test]
    public function codeowners_names_the_real_owner_for_every_critical_path(): void
    {
        $root = dirname(__DIR__, 2);
        $codeowners = file_get_contents($root . '/.github/CODEOWNERS');
        self::assertIsString($codeowners);

        foreach ([
            '* @jonesrussell',
            '/.github/workflows/ @jonesrussell',
            '/.github/CODEOWNERS @jonesrussell',
            '/bin/resolve-split-main-targets @jonesrussell',
            '/bin/configure-split-tag-protection @jonesrussell',
            '/bin/generate-release-evidence @jonesrussell',
            '/bin/check-release-* @jonesrussell',
            '/packages/deployer/ @jonesrussell',
            '/packages/foundation/resources/upgrade/ @jonesrussell',
            '/tools/ @jonesrussell',
            '/composer.json @jonesrussell',
        ] as $ownership) {
            self::assertStringContainsString($ownership, $codeowners);
        }

        preg_match_all('/@[a-zA-Z0-9_-]+/', $codeowners, $owners);
        self::assertSame(['@jonesrussell'], array_values(array_unique($owners[0])));
    }

    #[Test]
    public function admin_runbook_documents_truthful_solo_maintainer_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $runbook = file_get_contents($root . '/docs/REPO_ADMIN_SETUP.md');
        self::assertIsString($runbook);

        foreach ([
            'Require **0 human approvals** while only one eligible human exists.',
            'Require review threads to be resolved.',
            'Restrict administrator bypass to **pull-request mode**.',
            'Agent review is evidence, not a GitHub human approval',
            '`strict_required_status_checks_policy:true`',
            '`require_code_owner_review:false`',
        ] as $control) {
            self::assertStringContainsString($control, $runbook);
        }

        self::assertStringNotContainsString('Require at least **1 approving review**', $runbook);
        self::assertStringNotContainsString('bypass_mode:"always"', $runbook);
    }
}
