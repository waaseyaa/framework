<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Real-history acceptance proof for #2525's bounded landing preflight slice.
 */
#[CoversNothing]
final class LandingBasePreflightTest extends TestCase
{
    private string $root;
    private string $runner;
    private string $tmp;
    private string $repo;
    private string $base;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->runner = $this->root . '/bin/check-landing-base';
        $this->tmp = sys_get_temp_dir() . '/waaseyaa-landing-base-' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repository';
        new Filesystem()->mkdir($this->repo);
        $this->git(['init', '--quiet', '--initial-branch=main']);
        $this->git(['config', 'user.email', 'fixture@example.test']);
        $this->git(['config', 'user.name', 'Landing Fixture']);
        file_put_contents($this->repo . '/baseline.txt', "baseline\n");
        $this->git(['add', 'baseline.txt']);
        $this->git(['commit', '--quiet', '-m', 'baseline']);
        $this->base = $this->sha('HEAD');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    #[Test]
    public function linear_and_disjoint_main_drift_are_textually_clean_without_age_policy(): void
    {
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('src/feature.php', "feature\n", 'feature');
        $linear = $this->check($this->declaration($this->base, 'main', $this->base, 'feature', $head, $this->base));

        self::assertSame(0, $linear['exit'], $linear['stderr']);
        self::assertSame('textual_merge_clean', $linear['report']['verdict']);
        self::assertSame(0, $linear['report']['counts']['behind']);
        self::assertSame(['src/feature.php'], $linear['report']['changes']['declared_unique_range']['paths']);
        self::assertFalse($linear['report']['merge_authorized']);
        self::assertFalse($linear['report']['qualification']);

        $this->git(['switch', '--quiet', 'main']);
        $current = $this->commit('docs/evidence.md', "append\n", 'evidence only');
        $drift = $this->check($this->declaration($this->base, 'main', $current, 'feature', $head, $this->base));

        self::assertSame(0, $drift['exit'], $drift['stderr']);
        self::assertSame('textual_merge_clean', $drift['report']['verdict']);
        self::assertSame(1, $drift['report']['counts']['behind']);
        self::assertSame([], $drift['report']['overlap']['paths']);
    }

    #[Test]
    public function stacked_range_uses_only_the_declared_unique_suffix(): void
    {
        $this->git(['switch', '--quiet', '-c', 'prerequisite']);
        $prerequisite = $this->commit('src/a.php', "a\n", 'prerequisite');
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('src/b.php', "b\n", 'feature');

        $result = $this->check($this->declaration($this->base, 'prerequisite', $prerequisite, 'feature', $head, $prerequisite));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('textual_merge_clean', $result['report']['verdict']);
        self::assertSame($prerequisite, $result['report']['inputs']['unique_range']['base']);
        self::assertSame(['src/b.php'], $result['report']['changes']['declared_unique_range']['paths']);

        $notReady = $this->check($this->declaration($this->base, 'main', $this->base, 'feature', $head, $prerequisite));
        self::assertSame(2, $notReady['exit'], $notReady['stderr']);
        self::assertSame('indeterminate', $notReady['report']['verdict']);
        self::assertContains('declared_range_base_not_ancestor_of_current_base', $notReady['report']['reasons']);
        self::assertArrayNotHasKey('rebase_onto', $notReady['report']);
    }

    #[Test]
    public function overlap_is_reported_but_only_a_textual_conflict_requires_rebase(): void
    {
        file_put_contents($this->repo . '/shared.txt', "one\ntwo\nthree\n");
        $this->git(['add', 'shared.txt']);
        $this->git(['commit', '--quiet', '-m', 'shared base']);
        $recorded = $this->sha('HEAD');
        $this->git(['switch', '--quiet', '-c', 'feature']);
        file_put_contents($this->repo . '/shared.txt', "ONE\ntwo\nthree\n");
        $this->git(['commit', '--quiet', '-am', 'feature top']);
        $head = $this->sha('HEAD');
        $this->git(['switch', '--quiet', 'main']);
        file_put_contents($this->repo . '/shared.txt', "one\ntwo\nTHREE\n");
        $this->git(['commit', '--quiet', '-am', 'main bottom']);
        $current = $this->sha('HEAD');

        $clean = $this->check($this->declaration($recorded, 'main', $current, 'feature', $head, $recorded));
        self::assertSame(0, $clean['exit'], $clean['stderr']);
        self::assertSame('textual_merge_clean', $clean['report']['verdict']);
        self::assertSame(['shared.txt'], $clean['report']['overlap']['paths']);
        self::assertSame([], $clean['report']['merge']['conflict_paths']);

        $this->git(['switch', '--quiet', '-C', 'conflict', $recorded]);
        file_put_contents($this->repo . '/shared.txt', "one\ntwo\nFEATURE\n");
        $this->git(['commit', '--quiet', '-am', 'feature conflict']);
        $conflictHead = $this->sha('HEAD');
        $conflict = $this->check($this->declaration(
            $recorded,
            'main',
            $current,
            'conflict',
            $conflictHead,
            $recorded,
            generatedOutputs: ['shared.txt'],
        ));

        self::assertSame(1, $conflict['exit'], $conflict['stderr']);
        self::assertSame('rebase_required', $conflict['report']['verdict']);
        self::assertSame(['shared.txt'], $conflict['report']['merge']['conflict_paths']);
        self::assertSame(['shared.txt'], $conflict['report']['merge']['generated_output_conflicts']);
    }

    #[Test]
    public function an_equivalent_cherry_pick_is_nothing_to_land(): void
    {
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('src/already.php', "same\n", 'feature');
        $this->git(['switch', '--quiet', 'main']);
        $this->git(['cherry-pick', '--quiet', $head]);
        $current = $this->sha('HEAD');

        $result = $this->check($this->declaration($this->base, 'main', $current, 'feature', $head, $this->base));

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('nothing_to_land', $result['report']['verdict']);
        self::assertSame([], $result['report']['changes']['live_candidate']['paths']);
    }

    #[Test]
    public function declared_generated_and_contract_paths_are_reported_only_when_they_actually_change(): void
    {
        $generated = 'packages/admin/dist/app.js';
        $contract = 'docs/specs/example-contract.md';
        $unchangedContract = 'docs/specs/unchanged.md';
        foreach ([$generated, $contract, $unchangedContract] as $path) {
            new Filesystem()->mkdir(dirname($this->repo . '/' . $path));
            file_put_contents($this->repo . '/' . $path, "base\n");
        }
        $this->git(['add', 'packages/admin/dist', 'docs/specs']);
        $this->git(['commit', '--quiet', '-m', 'generated and contract base']);
        $recorded = $this->sha('HEAD');

        $this->git(['switch', '--quiet', '-c', 'feature']);
        file_put_contents($this->repo . '/' . $generated, "feature\n");
        file_put_contents($this->repo . '/' . $contract, "feature contract\n");
        $this->git(['commit', '--quiet', '-am', 'feature generated output']);
        $head = $this->sha('HEAD');
        $this->git(['switch', '--quiet', 'main']);
        file_put_contents($this->repo . '/' . $generated, "main\n");
        $this->git(['commit', '--quiet', '-am', 'main generated output']);
        $current = $this->sha('HEAD');

        $result = $this->check($this->declaration(
            $recorded,
            'main',
            $current,
            'feature',
            $head,
            $recorded,
            contractInputs: [$contract, $unchangedContract],
            generatedOutputs: [$generated],
        ));

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame([$contract], $result['report']['changes']['declared_unique_range']['contract_inputs']);
        self::assertSame([$generated], $result['report']['merge']['generated_output_conflicts']);
        self::assertContains($contract, $result['report']['changes']['declared_unique_range']['paths']);
    }

    #[Test]
    public function rewritten_recorded_base_requires_an_explicit_rebase_boundary(): void
    {
        $recorded = $this->commit('src/old-base.php', "old base\n", 'old main base');
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('src/feature.php', "feature\n", 'feature');
        $this->git(['switch', '--quiet', 'main']);
        $this->git(['reset', '--hard', '--quiet', $this->base]);
        $current = $this->commit('src/rewrite.php', "replacement\n", 'rewritten main');

        $result = $this->check($this->declaration($recorded, 'main', $current, 'feature', $head, $recorded));

        self::assertSame(2, $result['exit'], $result['stderr']);
        self::assertSame('indeterminate', $result['report']['verdict']);
        self::assertContains('recorded_base_not_ancestor_of_current_base', $result['report']['reasons']);
        self::assertArrayNotHasKey('rebase_onto', $result['report']);
    }

    #[Test]
    public function invalid_declared_range_dirty_tree_and_moved_ref_fail_closed(): void
    {
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('src/feature.php', "feature\n", 'feature');
        $invalid = $this->check($this->declaration($this->base, 'main', $this->base, 'feature', $head, $head));
        self::assertSame(2, $invalid['exit'], $invalid['stderr']);
        self::assertSame('indeterminate', $invalid['report']['verdict']);
        self::assertContains('declared_range_base_must_be_strict_ancestor_of_head', $invalid['report']['reasons']);

        $movedDeclaration = $this->declaration($this->base, 'main', $this->base, 'feature', $head, $this->base);
        $this->git(['switch', '--quiet', 'main']);
        $this->commit('src/main.php', "main\n", 'move main');
        $moved = $this->check($movedDeclaration);
        self::assertSame(2, $moved['exit'], $moved['stderr']);
        self::assertContains('current_base_ref_moved', $moved['report']['reasons']);

        file_put_contents($this->repo . '/baseline.txt', "dirty\n");
        $dirty = $this->check($this->declaration($this->base, 'main', $this->sha('main'), 'feature', $head, $this->base));
        self::assertSame(2, $dirty['exit'], $dirty['stderr']);
        self::assertContains('dirty_worktree', $dirty['report']['reasons']);
        self::assertNotSame([], $dirty['report']['worktree']['tracked_changes']);
    }

    #[Test]
    public function disconnected_and_multiple_merge_base_histories_are_indeterminate(): void
    {
        $tree = $this->sha('HEAD^{tree}');
        $other = $this->commitTree($tree, [], 'orphan');
        $otherHead = $this->commitTree($tree, [$other], 'orphan head');
        $this->git(['update-ref', 'refs/heads/disconnected', $otherHead]);
        $disconnected = $this->check($this->declaration($this->base, 'main', $this->base, 'disconnected', $otherHead, $other));
        self::assertSame(2, $disconnected['exit'], $disconnected['stderr']);
        self::assertContains('disconnected_history', $disconnected['report']['reasons']);

        $a1 = $this->commitTree($tree, [$this->base], 'a1');
        $b1 = $this->commitTree($tree, [$this->base], 'b1');
        $left = $this->commitTree($tree, [$a1, $b1], 'left');
        $right = $this->commitTree($tree, [$b1, $a1], 'right');
        $this->git(['update-ref', 'refs/heads/left', $left]);
        $this->git(['update-ref', 'refs/heads/right', $right]);
        $multiple = $this->check($this->declaration($this->base, 'left', $left, 'right', $right, $b1));
        self::assertSame(2, $multiple['exit'], $multiple['stderr']);
        self::assertContains('multiple_merge_bases', $multiple['report']['reasons']);
    }

    #[Test]
    public function a_shallow_repository_is_refused_before_topology_is_inferred(): void
    {
        $this->commit('history.txt', "one\n", 'one');
        $this->commit('history.txt', "two\n", 'two');
        $shallow = $this->tmp . '/shallow';
        $this->gitRaw(['clone', '--quiet', '--depth=1', 'file://' . $this->repo, $shallow], dirname($this->repo));
        $sha = trim($this->gitRaw(['rev-parse', 'HEAD'], $shallow));
        $declaration = $this->writeDeclaration([
            'schema' => 'waaseyaa.landing-base.v1',
            'recorded_base' => $sha,
            'current_base' => ['ref' => 'HEAD', 'sha' => $sha],
            'head' => ['ref' => 'HEAD', 'sha' => $sha],
            'unique_range' => ['base' => $sha, 'head' => $sha],
            'contract_inputs' => [],
            'generated_outputs' => [],
        ]);

        $result = $this->check($declaration, $shallow);

        self::assertSame(2, $result['exit'], $result['stderr']);
        self::assertContains('shallow_repository', $result['report']['reasons']);
    }

    #[Test]
    public function empty_unique_range_does_not_report_unrelated_main_changes(): void
    {
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $this->git(['commit', '--quiet', '--allow-empty', '-m', 'empty candidate']);
        $head = $this->sha('HEAD');
        $this->git(['switch', '--quiet', 'main']);
        $current = $this->commit('unrelated.txt', "main\n", 'main change');
        $result = $this->check($this->declaration($this->base, 'main', $current, 'feature', $head, $this->base));
        self::assertSame('nothing_to_land', $result['report']['verdict']);
        self::assertSame([], $result['report']['changes']['live_candidate']['paths']);
    }

    #[Test]
    public function unique_paths_are_literal_not_git_patterns(): void
    {
        $this->git(['switch', '--quiet', '-c', 'feature']);
        $head = $this->commit('file[ab].txt', "feature\n", 'literal filename');
        $this->git(['switch', '--quiet', 'main']);
        $current = $this->commit('filea.txt', "main\n", 'unrelated matching name');
        $result = $this->check($this->declaration($this->base, 'main', $current, 'feature', $head, $this->base));
        self::assertSame(['file[ab].txt'], $result['report']['changes']['live_candidate']['paths']);
    }

    /** @return array{exit:int, stderr:string, report:array<string,mixed>} */
    private function check(string $declaration, ?string $repo = null): array
    {
        self::assertFileExists($this->runner, 'bin/check-landing-base must exist.');
        $process = new Process([PHP_BINARY, $this->runner, '--repo=' . ($repo ?? $this->repo), '--declaration=' . $declaration]);
        $exit = $process->run();
        $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        return ['exit' => $exit, 'stderr' => $process->getErrorOutput(), 'report' => $report];
    }

    private function declaration(
        string $recordedBase,
        string $currentRef,
        string $currentSha,
        string $headRef,
        string $headSha,
        string $rangeBase,
        array $contractInputs = [],
        array $generatedOutputs = [],
    ): string {
        return $this->writeDeclaration([
            'schema' => 'waaseyaa.landing-base.v1',
            'recorded_base' => $recordedBase,
            'current_base' => ['ref' => $currentRef, 'sha' => $currentSha],
            'head' => ['ref' => $headRef, 'sha' => $headSha],
            'unique_range' => ['base' => $rangeBase, 'head' => $headSha],
            'contract_inputs' => $contractInputs,
            'generated_outputs' => $generatedOutputs,
        ]);
    }

    /** @param array<string,mixed> $declaration */
    private function writeDeclaration(array $declaration): string
    {
        $path = $this->tmp . '/declaration-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($declaration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        return $path;
    }

    private function commit(string $path, string $contents, string $message): string
    {
        $absolute = $this->repo . '/' . $path;
        new Filesystem()->mkdir(dirname($absolute));
        file_put_contents($absolute, $contents);
        $this->git(['add', $path]);
        $this->git(['commit', '--quiet', '-m', $message]);
        return $this->sha('HEAD');
    }

    /** @param list<string> $parents */
    private function commitTree(string $tree, array $parents, string $message): string
    {
        $arguments = ['commit-tree', $tree];
        foreach ($parents as $parent) {
            array_push($arguments, '-p', $parent);
        }
        return trim($this->gitRaw($arguments, $this->repo, $message . "\n"));
    }

    private function sha(string $ref): string
    {
        return trim($this->gitRaw(['rev-parse', $ref], $this->repo));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        return $this->gitRaw($arguments, $this->repo);
    }

    /** @param list<string> $arguments */
    private function gitRaw(array $arguments, string $cwd, ?string $input = null): string
    {
        $process = new Process([$this->root . '/bin/git', '-C', $cwd, ...$arguments]);
        if ($input !== null) {
            $process->setInput($input);
        }
        $exit = $process->run();
        self::assertSame(0, $exit, $process->getOutput() . $process->getErrorOutput());
        return $process->getOutput();
    }
}
