<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DeliveryLedgerMergeGuardTest extends TestCase
{
    #[Test]
    public function native_merge_is_head_pinned_and_requires_strict_custody_checks(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/ledger-merge-' . bin2hex(random_bytes(8));
        mkdir($fixture);
        try {
            file_put_contents($fixture . '/gh', <<<'SH'
                #!/usr/bin/env bash
                set -eu
                if [ "$1" = pr ] && [ "$2" = view ]; then cat "$FIXTURE/pr.json"; exit; fi
                if [ "$1" = api ]; then cat "$FIXTURE/rules.json"; exit; fi
                if [ "$1" = pr ] && [ "$2" = merge ]; then printf '%s\n' "$*" >> "$FIXTURE/merges"; exit "${MERGE_EXIT:-0}"; fi
                exit 99
                SH);
            chmod($fixture . '/gh', 0o755);
            $pr = ['headRefOid' => str_repeat('a', 40), 'baseRefName' => 'main', 'state' => 'OPEN', 'isDraft' => false];
            $rule = ['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => true, 'required_status_checks' => [['context' => 'ci/verify-gates', 'integration_id' => 15368]]]];
            file_put_contents($fixture . '/pr.json', json_encode($pr, JSON_THROW_ON_ERROR));
            file_put_contents($fixture . '/rules.json', json_encode([$rule], JSON_THROW_ON_ERROR));
            $env = ['PATH' => $fixture . ':' . getenv('PATH'), 'FIXTURE' => $fixture, 'GITHUB_REPOSITORY' => 'waaseyaa/framework', 'PR_NUMBER' => '42'];
            $run = static fn(array $override = []): Process => new Process(['bash', $root . '/bin/enable-governed-auto-merge'], $root, array_replace($env, $override));
            $p = $run();
            self::assertSame(0, $p->run(), $p->getErrorOutput());
            $merge = (string) file_get_contents($fixture . '/merges');
            self::assertStringContainsString('--match-head-commit ' . str_repeat('a', 40), $merge);
            self::assertStringContainsString('--auto --squash', $merge);
            self::assertStringNotContainsString('--admin', $merge);
            unlink($fixture . '/merges');

            $p = $run(['MERGE_EXIT' => '7']);
            self::assertSame(7, $p->run());
            self::assertCount(1, file($fixture . '/merges', FILE_IGNORE_NEW_LINES));
            unlink($fixture . '/merges');

            $badRuleSets = [
                [],
                [array_replace_recursive($rule, ['parameters' => ['strict_required_status_checks_policy' => false]])],
                [array_replace_recursive($rule, ['parameters' => ['required_status_checks' => [['context' => 'unrelated']]]])],
                [array_replace_recursive($rule, ['parameters' => ['required_status_checks' => [['integration_id' => 99]]]])],
            ];
            foreach ($badRuleSets as $badRules) {
                file_put_contents($fixture . '/rules.json', json_encode($badRules, JSON_THROW_ON_ERROR));
                $p = $run();
                self::assertNotSame(0, $p->run());
                self::assertFileDoesNotExist($fixture . '/merges');
            }
            file_put_contents($fixture . '/rules.json', json_encode([$rule], JSON_THROW_ON_ERROR));
            foreach ([['headRefOid' => 'moved'], ['baseRefName' => 'other'], ['state' => 'CLOSED'], ['isDraft' => true]] as $badPr) {
                file_put_contents($fixture . '/pr.json', json_encode(array_replace($pr, $badPr), JSON_THROW_ON_ERROR));
                $p = $run();
                self::assertNotSame(0, $p->run());
                self::assertFileDoesNotExist($fixture . '/merges');
            }
        } finally {
            new Filesystem()->remove($fixture);
        }
    }

    #[Test]
    public function hosted_adapter_preserves_the_whole_push_baseline(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile($root . '/.github/workflows/ci.yml');
        $steps = $workflow['jobs']['verify-gates']['steps'];
        $step = array_values(array_filter($steps, static fn(array $s): bool => ($s['name'] ?? '') === 'Verify append-only delivery agent events'))[0];
        $fixture = sys_get_temp_dir() . '/ledger-adapter-' . bin2hex(random_bytes(8));
        mkdir($fixture);
        try {
            file_put_contents($fixture . '/git', <<<'SH'
                #!/usr/bin/env bash
                set -eu
                if [ "$*" = 'rev-parse HEAD' ]; then printf '%s\n' "$CHECKED_OUT"; exit; fi
                if [ "$*" = "rev-parse $EXACT_SHA^1" ]; then printf '%s\n' "$FIRST_PARENT"; exit; fi
                exit 99
                SH);
            file_put_contents($fixture . '/php', <<<'SH'
                #!/usr/bin/env bash
                printf '%s\n' "$@" > "$FIXTURE/invocation"
                SH);
            chmod($fixture . '/git', 0o755);
            chmod($fixture . '/php', 0o755);
            $env = ['PATH' => $fixture . ':' . getenv('PATH'), 'FIXTURE' => $fixture,
                'CHECKED_OUT' => str_repeat('a', 40), 'EXACT_SHA' => str_repeat('a', 40),
                'EVENT_BASE_SHA' => str_repeat('b', 40), 'PR_HEAD_SHA' => str_repeat('c', 40),
                'FIRST_PARENT' => str_repeat('d', 40)];
            foreach (['pull_request', 'push', 'workflow_dispatch'] as $event) {
                $p = new Process(['bash', '-c', $step['run']], $root, $env + ['EVENT_NAME' => $event]);
                self::assertSame(0, $p->run(), $p->getErrorOutput());
                $args = file($fixture . '/invocation', FILE_IGNORE_NEW_LINES);
                self::assertContains('--candidate=' . $env['EXACT_SHA'], $args);
                self::assertContains('--base=' . ($event === 'workflow_dispatch' ? $env['FIRST_PARENT'] : $env['EVENT_BASE_SHA']), $args);
                self::assertSame($event === 'pull_request', in_array('--head=' . $env['PR_HEAD_SHA'], $args, true));
                unlink($fixture . '/invocation');
            }
            foreach ([['EVENT_NAME' => 'push', 'CHECKED_OUT' => str_repeat('e', 40)], ['EVENT_NAME' => 'unknown']] as $override) {
                $p = new Process(['bash', '-c', $step['run']], $root, array_replace($env, $override));
                self::assertNotSame(0, $p->run());
                self::assertFileDoesNotExist($fixture . '/invocation');
            }
        } finally {
            new Filesystem()->remove($fixture);
        }
    }
}
