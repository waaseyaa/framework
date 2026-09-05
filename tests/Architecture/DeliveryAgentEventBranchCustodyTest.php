<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DeliveryAgentEventBranchCustodyTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $fixtures = [];

    private int $eventSequence = 1;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $this->removeTree($fixture);
        }
    }

    #[Test]
    public function branch_mode_uses_the_unique_merge_base_when_main_appends_after_the_branch_forks(): void
    {
        $repo = $this->newRepository();
        $fork = $this->sha($repo);
        $this->git($repo, ['branch', 'feature']);

        $this->appendEvent($repo);
        $mainAfterAppend = $this->commitAll($repo, 'main appends accepted custody');

        $this->git($repo, ['checkout', '--quiet', 'feature']);
        file_put_contents($repo . '/source.txt', "branch work\n");
        $branchHead = $this->commitAll($repo, 'branch source change');

        $legacy = $this->checker($repo, ['--base=' . $mainAfterAppend]);
        self::assertSame(1, $legacy['exit'], $legacy['stderr'] . $legacy['stdout']);
        self::assertStringContainsString('not an exact byte-prefix extension', $legacy['stderr']);

        $branch = $this->checker($repo, ['--branch-base=' . $mainAfterAppend]);
        self::assertSame(0, $branch['exit'], $branch['stderr'] . $branch['stdout']);
        self::assertStringContainsString('mode=branch', $branch['stdout']);
        self::assertStringContainsString('branch_base=' . $mainAfterAppend, $branch['stdout']);
        self::assertStringContainsString('head=' . $branchHead, $branch['stdout']);
        self::assertStringContainsString('merge_base=' . $fork, $branch['stdout']);
    }

    #[Test]
    public function immutable_pr_candidate_accepts_both_appends_and_refuses_a_resolution_that_drops_main(): void
    {
        $repo = $this->newRepository();
        $fork = $this->sha($repo);
        $this->git($repo, ['branch', 'feature']);

        $this->appendEvent($repo);
        $base = $this->commitAll($repo, 'main custody append');

        $this->git($repo, ['checkout', '--quiet', 'feature']);
        $this->appendEvent($repo);
        $head = $this->commitAll($repo, 'feature custody append');

        $forkLedger = $this->gitShowPath($repo, $fork, 'ops/observability/delivery-agent-events-v1.jsonl');
        $headLedger = $this->gitShowPath($repo, $head, 'ops/observability/delivery-agent-events-v1.jsonl');
        self::assertStringStartsWith($forkLedger, $headLedger);
        $baseLedger = $this->gitShowPath($repo, $base, 'ops/observability/delivery-agent-events-v1.jsonl');
        file_put_contents(
            $repo . '/ops/observability/delivery-agent-events-v1.jsonl',
            $baseLedger . substr($headLedger, strlen($forkLedger)),
        );
        $this->git($repo, ['add', 'ops/observability/delivery-agent-events-v1.jsonl']);
        $acceptedTree = trim($this->git($repo, ['write-tree'])['stdout']);
        $candidate = $this->commitTree($repo, $acceptedTree, [$base, $head], 'valid merge candidate');
        $valid = $this->checker($repo, [
            '--candidate=' . $candidate,
            '--base=' . $base,
            '--head=' . $head,
        ]);
        self::assertSame(0, $valid['exit'], $valid['stderr'] . $valid['stdout']);
        self::assertStringContainsString('mode=candidate', $valid['stdout']);
        self::assertStringContainsString('candidate=' . $candidate, $valid['stdout']);

        $branchTree = trim($this->git($repo, ['rev-parse', $head . '^{tree}'])['stdout']);
        $malicious = $this->commitTree($repo, $branchTree, [$base, $head], 'drops main custody append');
        $refused = $this->checker($repo, [
            '--candidate=' . $malicious,
            '--base=' . $base,
            '--head=' . $head,
        ]);
        self::assertSame(1, $refused['exit'], $refused['stderr'] . $refused['stdout']);
        self::assertStringContainsString('not an exact byte-prefix extension', $refused['stderr']);
    }

    #[Test]
    public function push_candidate_compares_the_event_base_to_the_final_commit_across_multiple_commits(): void
    {
        $repo = $this->newRepository();
        $base = $this->sha($repo);
        $ledger = $repo . '/ops/observability/delivery-agent-events-v1.jsonl';
        $lines = explode("\n", rtrim((string) file_get_contents($ledger), "\n"));
        array_shift($lines);
        file_put_contents($ledger, implode("\n", $lines) . "\n");
        $this->commitAll($repo, 'remove accepted history');

        file_put_contents($repo . '/source.txt', "final no-op for the ledger\n");
        $candidate = $this->commitAll($repo, 'final pushed commit');
        $result = $this->checker($repo, ['--candidate=' . $candidate, '--base=' . $base]);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('not an exact byte-prefix extension', $result['stderr']);
    }

    #[Test]
    public function immutable_candidate_refuses_duplicate_and_orphan_causal_events(): void
    {
        $duplicateRepo = $this->newRepository();
        $duplicateBase = $this->sha($duplicateRepo);
        $ledger = $duplicateRepo . '/ops/observability/delivery-agent-events-v1.jsonl';
        $firstLine = strtok((string) file_get_contents($ledger), "\n");
        self::assertIsString($firstLine);
        file_put_contents($ledger, $firstLine . "\n", FILE_APPEND);
        $duplicateCandidate = $this->commitAll($duplicateRepo, 'duplicate event');
        $duplicate = $this->checker($duplicateRepo, [
            '--candidate=' . $duplicateCandidate,
            '--base=' . $duplicateBase,
        ]);
        self::assertSame(1, $duplicate['exit'], $duplicate['stderr'] . $duplicate['stdout']);
        self::assertStringContainsString('duplicates event_id', $duplicate['stderr']);

        $causalRepo = $this->newRepository();
        $causalBase = $this->sha($causalRepo);
        $this->appendEvent($causalRepo, '99999999-9999-4999-8999-999999999999');
        $causalCandidate = $this->commitAll($causalRepo, 'orphan causal event');
        $causal = $this->checker($causalRepo, [
            '--candidate=' . $causalCandidate,
            '--base=' . $causalBase,
        ]);
        self::assertSame(1, $causal['exit'], $causal['stderr'] . $causal['stdout']);
        self::assertStringContainsString('causation_event_id must name an earlier event', $causal['stderr']);
    }

    #[Test]
    public function immutable_candidate_refuses_any_change_to_the_published_schema(): void
    {
        $repo = $this->newRepository();
        $base = $this->sha($repo);
        $schemaPath = $repo . '/ops/observability/delivery-agent-event-v1.schema.json';
        $schema = json_decode((string) file_get_contents($schemaPath), true, flags: JSON_THROW_ON_ERROR);
        $schema['title'] = ($schema['title'] ?? 'Delivery agent event') . ' altered';
        file_put_contents($schemaPath, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $candidate = $this->commitAll($repo, 'alter schema');
        $result = $this->checker($repo, ['--candidate=' . $candidate, '--base=' . $base]);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('published v1 schema is immutable', $result['stderr']);
    }

    #[Test]
    public function immutable_candidate_requires_the_declared_base_to_track_both_authority_files(): void
    {
        $repo = $this->newRepository();
        $trackedTree = trim($this->git($repo, ['rev-parse', 'HEAD^{tree}'])['stdout']);
        $emptyTree = trim($this->git($repo, ['mktree'], '')['stdout']);
        $base = $this->commitTree($repo, $emptyTree, [], 'base without authority files');
        $candidate = $this->commitTree($repo, $trackedTree, [$base], 'candidate introduces unanchored authority files');

        $result = $this->checker($repo, ['--candidate=' . $candidate, '--base=' . $base]);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('base must track the canonical ledger', $result['stderr']);
        self::assertStringContainsString('base must track the canonical schema', $result['stderr']);
    }

    #[Test]
    public function immutable_pr_candidate_refuses_wrong_parent_order_and_extra_parents(): void
    {
        $repo = $this->newRepository();
        $base = $this->sha($repo);
        $tree = trim($this->git($repo, ['rev-parse', $base . '^{tree}'])['stdout']);
        $head = $this->commitTree($repo, $tree, [$base], 'head');
        $third = $this->commitTree($repo, $tree, [$base], 'third');
        $wrongOrder = $this->commitTree($repo, $tree, [$head, $base], 'wrong parent order');
        $tooMany = $this->commitTree($repo, $tree, [$base, $head, $third], 'octopus candidate');

        foreach ([$wrongOrder, $tooMany] as $candidate) {
            $result = $this->checker($repo, [
                '--candidate=' . $candidate,
                '--base=' . $base,
                '--head=' . $head,
            ]);
            self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertStringContainsString('exactly the declared base and head as its two parents', $result['stderr']);
        }
    }

    #[Test]
    public function immutable_mode_refuses_mutable_inputs_and_incompatible_authorities(): void
    {
        $repo = $this->newRepository();
        $sha = $this->sha($repo);
        $ledger = $repo . '/ops/observability/delivery-agent-events-v1.jsonl';
        $cases = [
            ['--candidate=HEAD', '--base=' . $sha],
            ['--candidate=' . $sha, '--base=main'],
            ['--head=' . $sha, '--base=' . $sha],
            ['--candidate=' . $sha, '--base=' . $sha, '--ledger=' . $ledger],
            ['--branch-base=' . $sha, '--base=' . $sha],
        ];

        foreach ($cases as $arguments) {
            $result = $this->checker($repo, $arguments);
            self::assertSame(2, $result['exit'], implode(' ', $arguments) . "\n" . $result['stderr'] . $result['stdout']);
        }
    }

    #[Test]
    public function immutable_candidate_reads_tracked_bytes_and_cannot_be_repaired_by_a_dirty_fixture(): void
    {
        $repo = $this->newRepository();
        $base = $this->sha($repo);
        $ledger = $repo . '/ops/observability/delivery-agent-events-v1.jsonl';
        $accepted = (string) file_get_contents($ledger);
        file_put_contents($ledger, '');
        $candidate = $this->commitAll($repo, 'corrupt candidate ledger');

        file_put_contents($ledger, $accepted);
        $worktree = $this->checker($repo);
        self::assertSame(0, $worktree['exit'], $worktree['stderr'] . $worktree['stdout']);

        $candidateResult = $this->checker($repo, [
            '--candidate=' . $candidate,
            '--base=' . $base,
        ]);
        self::assertSame(1, $candidateResult['exit'], $candidateResult['stderr'] . $candidateResult['stdout']);
        self::assertStringContainsString('ledger must contain at least one event', $candidateResult['stderr']);
    }

    #[Test]
    public function branch_mode_refuses_missing_and_disconnected_histories(): void
    {
        $repo = $this->newRepository();
        $base = $this->sha($repo);
        $missing = $this->checker($repo, ['--branch-base=missing-ref']);
        self::assertSame(2, $missing['exit'], $missing['stderr'] . $missing['stdout']);
        self::assertStringContainsString('could not resolve branch base', $missing['stderr']);

        $tree = trim($this->git($repo, ['rev-parse', $base . '^{tree}'])['stdout']);
        $orphan = $this->commitTree($repo, $tree, [], 'disconnected root');
        $this->git($repo, ['checkout', '--quiet', '--detach', $orphan]);
        $disconnected = $this->checker($repo, ['--branch-base=' . $base]);
        self::assertSame(1, $disconnected['exit'], $disconnected['stderr'] . $disconnected['stdout']);
        self::assertStringContainsString('exactly one merge base', $disconnected['stderr']);
    }

    #[Test]
    public function branch_mode_refuses_criss_cross_history_with_multiple_merge_bases(): void
    {
        $repo = $this->newRepository();
        $root = $this->sha($repo);
        $tree = trim($this->git($repo, ['rev-parse', $root . '^{tree}'])['stdout']);
        $left = $this->commitTree($repo, $tree, [$root], 'left');
        $right = $this->commitTree($repo, $tree, [$root], 'right');
        $leftMerge = $this->commitTree($repo, $tree, [$left, $right], 'left merge');
        $rightMerge = $this->commitTree($repo, $tree, [$right, $left], 'right merge');
        $this->git($repo, ['checkout', '--quiet', '--detach', $rightMerge]);
        $result = $this->checker($repo, ['--branch-base=' . $leftMerge]);

        self::assertSame(1, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('exactly one merge base', $result['stderr']);
    }

    #[Test]
    public function new_history_modes_refuse_shallow_repositories_that_can_hide_ancestry(): void
    {
        $branchRepo = $this->newRepository();
        $root = $this->sha($branchRepo);
        $tree = trim($this->git($branchRepo, ['rev-parse', $root . '^{tree}'])['stdout']);
        $leftBase = $this->commitTree($branchRepo, $tree, [$root], 'left base');
        $rightBase = $this->commitTree($branchRepo, $tree, [$root], 'right base');
        $leftChild = $this->commitTree($branchRepo, $tree, [$leftBase], 'left child');
        $rightChild = $this->commitTree($branchRepo, $tree, [$rightBase], 'right child');
        $leftMerge = $this->commitTree($branchRepo, $tree, [$leftChild, $rightBase], 'left merge');
        $rightMerge = $this->commitTree($branchRepo, $tree, [$rightChild, $leftBase], 'right merge');
        $this->git($branchRepo, ['checkout', '--quiet', '--detach', $rightMerge]);

        $complete = $this->checker($branchRepo, ['--branch-base=' . $leftMerge]);
        self::assertSame(1, $complete['exit'], $complete['stderr'] . $complete['stdout']);
        self::assertStringContainsString('exactly one merge base', $complete['stderr']);

        $this->markShallow($branchRepo, $rightChild);
        $shallowBranch = $this->checker($branchRepo, ['--branch-base=' . $leftMerge]);
        self::assertSame(1, $shallowBranch['exit'], $shallowBranch['stderr'] . $shallowBranch['stdout']);
        self::assertStringContainsString('shallow repository', $shallowBranch['stderr']);

        $candidateRepo = $this->newRepository();
        $base = $this->sha($candidateRepo);
        $this->appendEvent($candidateRepo);
        $candidate = $this->commitAll($candidateRepo, 'valid append');
        $this->markShallow($candidateRepo, $base);
        $shallowCandidate = $this->checker($candidateRepo, [
            '--candidate=' . $candidate,
            '--base=' . $base,
        ]);
        self::assertSame(1, $shallowCandidate['exit'], $shallowCandidate['stderr'] . $shallowCandidate['stdout']);
        self::assertStringContainsString('shallow repository', $shallowCandidate['stderr']);

        $legacy = $this->checker($candidateRepo, ['--base=' . $base]);
        self::assertSame(0, $legacy['exit'], $legacy['stderr'] . $legacy['stdout']);
    }

    #[Test]
    public function branch_mode_refuses_rewritten_history_and_a_changed_published_schema(): void
    {
        $ledgerRepo = $this->newRepository();
        $ledgerBase = $this->sha($ledgerRepo);
        $ledgerPath = $ledgerRepo . '/ops/observability/delivery-agent-events-v1.jsonl';
        $lines = explode("\n", rtrim((string) file_get_contents($ledgerPath), "\n"));
        [$lines[0], $lines[1]] = [$lines[1], $lines[0]];
        file_put_contents($ledgerPath, implode("\n", $lines) . "\n");
        $this->commitAll($ledgerRepo, 'rewrite prior ledger bytes');

        $ledgerResult = $this->checker($ledgerRepo, ['--branch-base=' . $ledgerBase]);
        self::assertSame(1, $ledgerResult['exit'], $ledgerResult['stderr'] . $ledgerResult['stdout']);
        self::assertStringContainsString('not an exact byte-prefix extension', $ledgerResult['stderr']);

        $schemaRepo = $this->newRepository();
        $schemaBase = $this->sha($schemaRepo);
        $schemaPath = $schemaRepo . '/ops/observability/delivery-agent-event-v1.schema.json';
        $schema = json_decode((string) file_get_contents($schemaPath), true, flags: JSON_THROW_ON_ERROR);
        $schema['title'] = ($schema['title'] ?? 'Delivery agent event') . ' branch mutation';
        file_put_contents($schemaPath, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $this->commitAll($schemaRepo, 'change schema in branch');

        $schemaResult = $this->checker($schemaRepo, ['--branch-base=' . $schemaBase]);
        self::assertSame(1, $schemaResult['exit'], $schemaResult['stderr'] . $schemaResult['stdout']);
        self::assertStringContainsString('published v1 schema is immutable', $schemaResult['stderr']);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function checker(string $repo, array $arguments = []): array
    {
        $process = new Process([PHP_BINARY, $repo . '/bin/check-delivery-agent-events', ...$arguments], $repo);
        $process->setTimeout(30);
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 255,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function newRepository(): string
    {
        $repo = sys_get_temp_dir() . '/waaseyaa-ledger-custody-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($repo . '/ops/observability', 0o777, true));
        self::assertTrue(mkdir($repo . '/bin/lib', 0o777, true));
        self::assertTrue(copy($this->root . '/ops/observability/delivery-agent-event-v1.schema.json', $repo . '/ops/observability/delivery-agent-event-v1.schema.json'));
        self::assertTrue(copy($this->root . '/ops/observability/delivery-agent-events-v1.jsonl', $repo . '/ops/observability/delivery-agent-events-v1.jsonl'));
        self::assertTrue(copy($this->root . '/bin/check-delivery-agent-events', $repo . '/bin/check-delivery-agent-events'));
        self::assertTrue(copy($this->root . '/bin/lib/delivery-agent-event-set.php', $repo . '/bin/lib/delivery-agent-event-set.php'));
        self::assertTrue(copy($this->root . '/bin/git', $repo . '/bin/git'));
        chmod($repo . '/bin/check-delivery-agent-events', 0o755);
        chmod($repo . '/bin/git', 0o755);
        self::assertTrue(symlink($this->root . '/vendor', $repo . '/vendor'));
        $this->fixtures[] = $repo;

        $this->git($repo, ['init', '--quiet', '--initial-branch=main']);
        $this->git($repo, ['config', 'user.name', 'Ledger Fixture']);
        $this->git($repo, ['config', 'user.email', 'ledger-fixture@example.invalid']);
        $this->git($repo, ['add', 'ops/observability', 'bin', 'vendor']);
        $this->git($repo, ['commit', '--quiet', '-m', 'initial custody']);

        return $repo;
    }

    private function appendEvent(string $repo, ?string $causationEventId = null): void
    {
        $sequence = $this->eventSequence++;
        $event = [
            'schema_version' => 'delivery-agent-event/v1',
            'event_id' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
            'event_type' => 'review_started',
            'recorded_at' => sprintf('2099-01-01T00:00:%02dZ', $sequence),
            'occurred_at' => null,
            'repository' => 'waaseyaa/framework',
            'pull_request' => 2900,
            'head_sha' => str_repeat('a', 40),
            'actor' => ['kind' => 'codex', 'name' => 'Codex', 'model' => null],
            'evidence_kind' => 'observed',
            'causation_event_id' => $causationEventId,
            'review_depth' => null,
            'outcome' => null,
            'finding_count' => null,
            'token_count' => null,
            'elapsed_ms' => null,
            'source_url' => null,
            'notes' => null,
            'verification' => null,
            'adjudication' => null,
        ];
        file_put_contents(
            $repo . '/ops/observability/delivery-agent-events-v1.jsonl',
            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND,
        );
    }

    private function commitAll(string $repo, string $message): string
    {
        $this->git($repo, ['add', '--all']);
        $this->git($repo, ['commit', '--quiet', '-m', $message]);

        return $this->sha($repo);
    }

    /** @param list<string> $parents */
    private function commitTree(string $repo, string $tree, array $parents, string $message): string
    {
        $arguments = ['commit-tree', $tree];
        foreach ($parents as $parent) {
            $arguments[] = '-p';
            $arguments[] = $parent;
        }
        $result = $this->git($repo, $arguments, $message . "\n");

        return trim($result['stdout']);
    }

    private function sha(string $repo, string $ref = 'HEAD'): string
    {
        return trim($this->git($repo, ['rev-parse', $ref])['stdout']);
    }

    private function gitShowPath(string $repo, string $commit, string $path): string
    {
        return $this->git($repo, ['show', $commit . ':' . $path])['stdout'];
    }

    private function markShallow(string $repo, string $commit): void
    {
        $path = trim($this->git($repo, ['rev-parse', '--git-path', 'shallow'])['stdout']);
        if (!str_starts_with($path, '/')) {
            $path = $repo . '/' . $path;
        }
        file_put_contents($path, $commit . "\n");
    }

    /** @param list<string> $arguments @return array{stdout: string, stderr: string} */
    private function git(string $repo, array $arguments, ?string $input = null): array
    {
        $process = new Process([$this->root . '/bin/git', '-C', $repo, ...$arguments], $repo);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->setTimeout(30);
        $exit = $process->run();
        self::assertSame(0, $exit, implode(' ', $arguments) . "\n" . $process->getErrorOutput());

        return ['stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    private function removeTree(string $path): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove($path);
    }
}
