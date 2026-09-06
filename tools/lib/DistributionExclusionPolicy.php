<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/**
 * Canonical distribution-exclusion policy (#2648): load, render, and verify the
 * three production-artifact surfaces from support/distribution-exclusion-policy-v1.json.
 *
 * @api
 */
final class DistributionExclusionPolicy
{
    public const POLICY_RELATIVE_PATH = 'support/distribution-exclusion-policy-v1.json';

    /** @var array<string, mixed> */
    private array $policy;

    /** @param array<string, mixed> $policy */
    public function __construct(array $policy)
    {
        $this->policy = $policy;
        $this->validate();
    }

    public static function load(string $repositoryRoot): self
    {
        $path = rtrim($repositoryRoot, '/') . '/' . self::POLICY_RELATIVE_PATH;
        if (!is_file($path)) {
            throw new RuntimeException('Distribution exclusion policy is missing: ' . $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Distribution exclusion policy must be a JSON object.');
        }

        return new self($decoded);
    }

    /** @return list<string> */
    public function exportAttributeLines(): array
    {
        $ignoreLines = [];
        $keepLines = [];
        foreach ($this->categoryIds() as $categoryId) {
            $category = $this->category($categoryId);
            foreach ($category['export_ignore'] ?? [] as $pattern) {
                $ignoreLines[] = (string) $pattern . ' export-ignore';
            }
            foreach ($category['export_keep'] ?? [] as $pattern) {
                $keepLines[] = (string) $pattern . ' -export-ignore';
            }
        }

        sort($ignoreLines, SORT_STRING);
        sort($keepLines, SORT_STRING);

        return array_values(array_unique([...$ignoreLines, ...$keepLines]));
    }

    public function renderGitattributesSection(): string
    {
        $begin = (string) ($this->policy['composer_export']['section_begin'] ?? '');
        $end = (string) ($this->policy['composer_export']['section_end'] ?? '');
        $lines = [$begin];
        foreach ($this->exportAttributeLines() as $line) {
            $lines[] = $line;
        }
        $lines[] = $end;

        return implode("\n", $lines) . "\n";
    }

    public function renderDockerignoreSection(): string
    {
        $begin = (string) ($this->policy['docker_build_context']['section_begin'] ?? '');
        $end = (string) ($this->policy['docker_build_context']['section_end'] ?? '');
        $lines = [$begin, ''];

        foreach ($this->categoryIds() as $categoryId) {
            $category = $this->category($categoryId);
            $docker = $category['dockerignore'] ?? null;
            if (!is_array($docker)) {
                continue;
            }
            $description = (string) ($category['description'] ?? $categoryId);
            $lines[] = '# ' . $description;
            foreach ($docker['include'] ?? [] as $pattern) {
                $lines[] = (string) $pattern;
            }
            $lines[] = '';
        }

        $secrets = $this->category('secrets');
        $exceptLast = $secrets['dockerignore']['except_last'] ?? [];
        if ($exceptLast !== []) {
            $lines[] = '# Placeholder templates survive the secret patterns above; negations come last';
            $lines[] = '# because in .dockerignore the last matching pattern wins.';
            foreach ($exceptLast as $pattern) {
                $lines[] = (string) $pattern;
            }
            $lines[] = '';
        }

        $lines[] = $end;

        return implode("\n", array_map('rtrim', $lines)) . "\n";
    }

    public function renderDockerignoreFile(): string
    {
        $preamble = $this->policy['docker_build_context']['preamble'] ?? [];
        if (!is_array($preamble)) {
            throw new RuntimeException('docker_build_context.preamble must be an array.');
        }

        $chunks = [];
        foreach ($preamble as $line) {
            $chunks[] = (string) $line;
        }
        $chunks[] = '';
        $chunks[] = rtrim($this->renderDockerignoreSection());

        return implode("\n", $chunks) . "\n";
    }

    /** @return list<string> */
    public function rsyncExcludeAnchoredPatterns(): array
    {
        $patterns = [];
        foreach ($this->categoryIds() as $categoryId) {
            foreach ($this->category($categoryId)['rsync_exclude_anchored'] ?? [] as $pattern) {
                $patterns[] = (string) $pattern;
            }
        }

        sort($patterns, SORT_STRING);

        return array_values(array_unique($patterns));
    }

    /**
     * @return list<string>
     */
    public function forbiddenComposerExportPatterns(): array
    {
        $forbidden = $this->policy['approved_docs']['forbidden_patterns']['composer_export'] ?? [];
        if (!is_array($forbidden)) {
            throw new RuntimeException('approved_docs.forbidden_patterns.composer_export must be an array.');
        }

        return array_values(array_map(strval(...), $forbidden));
    }

    /**
     * @return list<string>
     */
    public function forbiddenDeployRsyncPatterns(): array
    {
        $forbidden = $this->policy['approved_docs']['forbidden_patterns']['deploy_rsync'] ?? [];
        if (!is_array($forbidden)) {
            throw new RuntimeException('approved_docs.forbidden_patterns.deploy_rsync must be an array.');
        }

        return array_values(array_map(strval(...), $forbidden));
    }

    public function docsAnchorForbiddenUnanchored(): string
    {
        return (string) ($this->policy['deploy_rsync']['docs_anchor']['forbidden_unanchored'] ?? 'docs/');
    }

    public function docsAnchorRequiredAnchored(): string
    {
        return (string) ($this->policy['deploy_rsync']['docs_anchor']['required_anchored'] ?? '/docs/');
    }

    public function managedSection(string $surface, string $contents): string
    {
        [$begin, $end] = match ($surface) {
            'gitattributes' => [
                (string) $this->policy['composer_export']['section_begin'],
                (string) $this->policy['composer_export']['section_end'],
            ],
            'dockerignore' => [
                (string) $this->policy['docker_build_context']['section_begin'],
                (string) $this->policy['docker_build_context']['section_end'],
            ],
            default => throw new RuntimeException('Unknown managed surface: ' . $surface),
        };

        $start = strpos($contents, $begin);
        $finish = strpos($contents, $end);
        if ($start === false || $finish === false || $finish < $start) {
            throw new RuntimeException("Managed section markers are missing for {$surface}.");
        }

        $finish += strlen($end);

        return substr($contents, $start, $finish - $start);
    }

    public function verifyGitattributes(string $path): array
    {
        $errors = [];
        if (!is_file($path)) {
            return ['.gitattributes is missing'];
        }

        $contents = (string) file_get_contents($path);
        try {
            $actual = rtrim($this->managedSection('gitattributes', $contents)) . "\n";
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        $expected = rtrim($this->renderGitattributesSection());
        if (rtrim($actual) !== $expected) {
            $errors[] = '.gitattributes managed export-ignore section drifted from policy (run: php bin/check-distribution-exclusion --render).';
        }

        foreach ($this->forbiddenComposerExportPatterns() as $pattern) {
            if (preg_match(
                '/(?:^|\R)\h*' . preg_quote($pattern, '/') . '\h+export-ignore(?:\h|$)/m',
                $contents,
            ) === 1) {
                $errors[] = "Approved docs guard: export-ignore must not use reflexive pattern `{$pattern}`.";
            }
        }

        return $errors;
    }

    public function verifyDockerignore(string $path): array
    {
        $errors = [];
        if (!is_file($path)) {
            return ['skeleton/.dockerignore is missing'];
        }

        $contents = (string) file_get_contents($path);
        try {
            $actual = rtrim($this->managedSection('dockerignore', $contents)) . "\n";
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        $expected = rtrim($this->renderDockerignoreSection());
        if (rtrim($actual) !== $expected) {
            $errors[] = 'skeleton/.dockerignore managed section drifted from policy (run: php bin/check-distribution-exclusion --render).';
        }

        return $errors;
    }

    /**
     * @param list<string> $workflowPaths
     *
     * @return list<string>
     */
    public function verifyDeployRsyncWorkflows(array $workflowPaths): array
    {
        $errors = [];
        $forbidden = $this->docsAnchorForbiddenUnanchored();
        $required = $this->docsAnchorRequiredAnchored();

        foreach ($workflowPaths as $workflowPath) {
            if (!is_file($workflowPath)) {
                continue;
            }
            $contents = (string) file_get_contents($workflowPath);
            if (!str_contains($contents, 'rsync')) {
                continue;
            }

            $excludes = $this->extractRsyncExcludes($contents);

            if (in_array($forbidden, $excludes, true)) {
                $errors[] = basename($workflowPath) . " uses unanchored --exclude='{$forbidden}' (use --exclude='{$required}' for repo-root docs only).";
            }

            foreach ($this->forbiddenDeployRsyncPatterns() as $pattern) {
                if (in_array($pattern, $excludes, true)) {
                    $errors[] = basename($workflowPath) . " reflexively excludes approved docs via --exclude='{$pattern}'.";
                }
            }

            foreach ($this->rsyncExcludeAnchoredPatterns() as $pattern) {
                $anchored = '/' . ltrim($pattern, '/');
                if (!in_array($anchored, $excludes, true)) {
                    $errors[] = basename($workflowPath) . " is missing required rsync exclusion `{$anchored}`.";
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function extractRsyncExcludes(string $contents): array
    {
        preg_match_all(
            '/--exclude(?:=|\h+)(?:\'([^\']*)\'|"([^"]*)"|([^\s\\\\]+))/',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        $patterns = [];
        foreach ($matches as $match) {
            foreach ([1, 2, 3] as $capture) {
                if (($match[$capture] ?? '') !== '') {
                    $patterns[] = $match[$capture];
                    break;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Prove git archive honors export-ignore for a throwaway repository.
     *
     * @return list<string>
     */
    public function proveGitArchiveExportIgnore(string $scratchDir): array
    {
        $repo = rtrim($scratchDir, '/') . '/archive-proof';
        try {
            $this->createArchiveProofTree($repo);
            $this->runCommand(['git', 'init', '-q'], $repo);
            $this->runCommand(['git', 'config', 'user.email', 'gate@waaseyaa.test'], $repo);
            $this->runCommand(['git', 'config', 'user.name', 'Distribution Exclusion Gate'], $repo);
            $this->runCommand(['git', 'add', '-A'], $repo);
            $this->runCommand(['git', 'commit', '-q', '-m', 'init'], $repo);

            $archiveTar = $this->runCommand(['git', 'archive', 'HEAD'], $repo);
            $entries = $this->tarEntries($archiveTar, $repo);

            return $this->verifyArchiveProofEntries($entries, 'git archive');
        } finally {
            $this->removeTree($repo);
        }
    }

    /**
     * Prove Composer's own archive command applies the same export attributes.
     *
     * @return list<string>
     */
    public function proveComposerArchiveExportIgnore(string $scratchDir): array
    {
        $repo = rtrim($scratchDir, '/') . '/composer-archive-proof';
        $output = rtrim($scratchDir, '/') . '/composer-archive-output';
        try {
            $this->createArchiveProofTree($repo);
            mkdir($output, 0o755, true);
            $this->runCommand([
                'composer',
                'archive',
                '--format=tar',
                '--dir=' . $output,
                '--file=proof',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
            ], $repo);

            $archive = $output . '/proof.tar';
            if (!is_file($archive)) {
                return ['Composer archive proof: composer did not create proof.tar.'];
            }
            $entries = $this->tarEntries((string) file_get_contents($archive), $repo);

            return $this->verifyArchiveProofEntries($entries, 'Composer archive');
        } finally {
            $this->removeTree($repo);
            $this->removeTree($output);
        }
    }

    /**
     * @return list<string>
     */
    public function selfTestSentinels(string $repositoryRoot, string $scratchDir): array
    {
        $errors = [];
        $gitattributesPath = rtrim($repositoryRoot, '/') . '/.gitattributes';
        $dockerignorePath = rtrim($repositoryRoot, '/') . '/skeleton/.dockerignore';
        $work = rtrim($scratchDir, '/') . '/surface-sentinels';
        $completeWorkflow = rtrim($repositoryRoot, '/') . '/tests/Fixtures/DistributionExclusion/workflows/complete.yml';
        $docsWorkflow = rtrim($repositoryRoot, '/') . '/tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml';

        try {
            $this->removeTree($work);
            mkdir($work, 0o755, true);

            $attributesFixture = $work . '/.gitattributes';
            $attributes = str_replace(
                "\n.mcp.json export-ignore\n",
                "\n.mcp.json export-ignore-mutated\n",
                (string) file_get_contents($gitattributesPath),
                $attributeMutations,
            );
            file_put_contents($attributesFixture, $attributes);
            if ($attributeMutations !== 1 || $this->verifyGitattributes($attributesFixture) === []) {
                $errors[] = 'Self-test sentinel for gitattributes did not fail when mutated.';
            }

            $dockerignoreFixture = $work . '/.dockerignore';
            $dockerignore = str_replace(
                "\n.mcp.json\n",
                "\n.mcp.json-mutated\n",
                (string) file_get_contents($dockerignorePath),
                $dockerMutations,
            );
            file_put_contents($dockerignoreFixture, $dockerignore);
            if ($dockerMutations !== 1 || $this->verifyDockerignore($dockerignoreFixture) === []) {
                $errors[] = 'Self-test sentinel for dockerignore did not fail when mutated.';
            }

            if (!is_file($completeWorkflow)) {
                $errors[] = 'Missing deploy_rsync complete fixture: tests/Fixtures/DistributionExclusion/workflows/complete.yml';
            } else {
                $complete = (string) file_get_contents($completeWorkflow);
                foreach ($this->rsyncExcludeAnchoredPatterns() as $pattern) {
                    $anchored = '/' . ltrim($pattern, '/');
                    $mutated = str_replace(
                        "            --exclude='{$anchored}' \\\n",
                        '',
                        $complete,
                        $workflowMutations,
                    );
                    $fixture = $work . '/deploy-' . hash('sha256', $anchored) . '.yml';
                    file_put_contents($fixture, $mutated);
                    if ($workflowMutations !== 1 || $this->verifyDeployRsyncWorkflows([$fixture]) === []) {
                        $errors[] = "Self-test sentinel for deploy_rsync did not fail when `{$anchored}` was omitted.";
                    }
                }
            }

            if (!is_file($docsWorkflow)) {
                $errors[] = 'Missing deploy_rsync sentinel fixture: tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml';
            } elseif ($this->verifyDeployRsyncWorkflows([$docsWorkflow]) === []) {
                $errors[] = 'Self-test sentinel for deploy_rsync did not fail on the unanchored-docs fixture.';
            }

            return $errors;
        } finally {
            $this->removeTree($work);
        }
    }

    public function writeRenderedSurfaces(string $repositoryRoot): void
    {
        $root = rtrim($repositoryRoot, '/');
        $this->upsertManagedSection(
            $root . '/.gitattributes',
            'gitattributes',
            $this->renderGitattributesSection(),
        );
        file_put_contents($root . '/skeleton/.dockerignore', $this->renderDockerignoreFile());
    }

    private function upsertManagedSection(string $path, string $surface, string $section): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Cannot render into missing file: {$path}");
        }

        $contents = (string) file_get_contents($path);
        [$begin, $end] = match ($surface) {
            'gitattributes' => [
                (string) $this->policy['composer_export']['section_begin'],
                (string) $this->policy['composer_export']['section_end'],
            ],
            default => throw new RuntimeException('upsertManagedSection only supports gitattributes.'),
        };

        $normalizedSection = rtrim($section) . "\n";
        if (str_contains($contents, $begin) && str_contains($contents, $end)) {
            $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s';
            $updated = preg_replace($pattern, $normalizedSection, $contents, 1);
            if (!is_string($updated)) {
                throw new RuntimeException("Failed to replace managed section in {$path}.");
            }
            file_put_contents($path, $updated);

            return;
        }

        file_put_contents($path, rtrim($contents) . "\n\n" . $normalizedSection);
    }

    /** @return list<string> */
    private function categoryIds(): array
    {
        $categories = $this->policy['categories'] ?? null;
        if (!is_array($categories)) {
            throw new RuntimeException('categories must be an object.');
        }

        $ids = [];
        foreach (array_keys($categories) as $id) {
            $ids[] = (string) $id;
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function category(string $id): array
    {
        $category = $this->policy['categories'][$id] ?? null;
        if (!is_array($category)) {
            throw new RuntimeException("categories.{$id} must be an object.");
        }

        return $category;
    }

    private function validate(): void
    {
        if (($this->policy['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('distribution-exclusion policy schema_version must be 1.');
        }

        foreach (['composer_export', 'approved_docs', 'categories', 'docker_build_context', 'deploy_rsync'] as $key) {
            if (!isset($this->policy[$key]) || !is_array($this->policy[$key])) {
                throw new RuntimeException("Policy key `{$key}` must be an object.");
            }
        }

        foreach ($this->categoryIds() as $categoryId) {
            $category = $this->category($categoryId);
            foreach (['dockerignore', 'export_ignore', 'rsync_exclude_anchored'] as $field) {
                if (!array_key_exists($field, $category)) {
                    throw new RuntimeException("categories.{$categoryId}.{$field} is required.");
                }
            }
        }
    }

    private function createArchiveProofTree(string $repo): void
    {
        $this->removeTree($repo);
        foreach ([
            'docs/specs',
            '.agents',
            'config',
            'storage/files',
            '.waaseyaa',
        ] as $directory) {
            mkdir($repo . '/' . $directory, 0o755, true);
        }

        $files = [
            'README.md' => "approved\n",
            'composer.json' => "{\"name\":\"waaseyaa/archive-proof\",\"type\":\"project\"}\n",
            'docs/specs/live.md' => "approved\n",
            '.env' => "SECRET=generated\n",
            'config/.env.staging' => "SECRET=generated\n",
            '.env.example' => "SECRET=\n",
            'config/.env.example' => "SECRET=\n",
            'composer.local.json' => "{}\n",
            '.mcp.json' => "{}\n",
            'config/.mcp.json' => "{}\n",
            '.agents/local' => "local\n",
            'storage/site.sqlite' => "operator\n",
            'storage/files/upload.txt' => "operator\n",
            '.waaseyaa/state.json' => "{}\n",
            '.waaseyaa-golden-sha' => "local\n",
            'support-contract-evidence.json' => "{}\n",
            '.gitattributes' => $this->renderGitattributesSection(),
        ];
        foreach ($files as $path => $contents) {
            file_put_contents($repo . '/' . $path, $contents);
        }
    }

    /** @return list<string> */
    private function tarEntries(string $archiveTar, string $cwd): array
    {
        $archiveList = $this->runCommand(['tar', '-tf', '-'], $cwd, stdin: $archiveTar);
        $entries = [];
        foreach (explode("\n", trim($archiveList)) as $line) {
            $line = trim($line);
            if ($line === '' || str_ends_with($line, '/')) {
                continue;
            }
            $entries[] = $line;
        }

        return $entries;
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private function verifyArchiveProofEntries(array $entries, string $proof): array
    {
        $errors = [];
        foreach ([
            '.env',
            'config/.env.staging',
            'composer.local.json',
            '.mcp.json',
            'config/.mcp.json',
            '.agents/local',
            'storage/site.sqlite',
            'storage/files/upload.txt',
            '.waaseyaa/state.json',
            '.waaseyaa-golden-sha',
            'support-contract-evidence.json',
        ] as $mustExclude) {
            if (in_array($mustExclude, $entries, true)) {
                $errors[] = "{$proof} proof: `{$mustExclude}` was exported despite policy.";
            }
        }

        foreach ([
            'README.md',
            'composer.json',
            'docs/specs/live.md',
            '.env.example',
            'config/.env.example',
        ] as $mustInclude) {
            if (!in_array($mustInclude, $entries, true)) {
                $errors[] = "{$proof} proof: approved path `{$mustInclude}` was missing from export.";
            }
        }

        return $errors;
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $cwd, ?string $stdin = null): string
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start command: ' . implode(' ', $command));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(trim("Command failed ({$exitCode}): " . implode(' ', $command) . "\n" . $stdout . $stderr));
        }

        return (string) $stdout;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
