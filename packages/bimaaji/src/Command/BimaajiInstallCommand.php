<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Command;

use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\InstalledManifest;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillResourceException;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Install\TargetFile;

// Note: `\Waaseyaa\CLI\Command\SymfonyCommandIO` is referenced inline (not via `use`) because cli is L6
// and bimaaji is L4. `bin/check-package-layers` scans `use` imports only, so inline
// FQCNs are the canonical way to type-hint across the L4→L6 boundary. The
// Symfony command metadata reference in BimaajiServiceProvider::consoleCommands() uses the
// same pattern (mirrors GraphDumpHandler).

/**
 * `bin/waaseyaa bimaaji:install` — install Waaseyaa framework guidelines + skills
 * into a consuming project for one or more agent clients.
 *
 * Surfaces the canonical Agent Skill set shipped inside `waaseyaa/bimaaji`
 * (`resources/skills/<id>/SKILL.md`) through per-client
 * {@see ClientTransformerInterface} implementations (Claude Code, Cursor,
 * Codex, Copilot, Gemini, Windsurf, Junie) and writes the resulting target
 * files to the project root. Nothing is read from the consuming project;
 * the source is the installed package (#2656).
 *
 * Flags:
 *
 * - `--client=<id>` — repeatable; selects which transformer(s) to run. When
 *   omitted, prompts interactively (aborts on non-TTY unless `--force`).
 * - `--features=<csv>` — `guidelines,skills` (default). Reserved for future
 *   filtering when the skill set carries category tags.
 * - `--dry-run` — print the would-be write set without touching the
 *   filesystem; returns exit 0.
 * - `--force` — skip every confirmation prompt and overwrite existing
 *   files unconditionally. Required when running non-interactively without
 *   `--dry-run`.
 *
 * Idempotency (FR-009): identical existing content is recognised by sha1
 * compare and counted as `unchanged` in the per-client summary.
 *
 * Marker-bounded (#2656): every generated file frames its payload in the
 * {@see ManagedRegion} markers. When an existing target already carries a
 * marker pair, only the text between the markers is replaced and every
 * byte outside them is preserved, so a re-run refreshes the framework's
 * guidance without touching a consumer's own notes. A file with no
 * markers is treated as wholly hand-authored and still requires `--force`
 * or an interactive confirmation before it is replaced.
 *
 * Sandbox discipline (NFR-002): every target path must resolve under the
 * project root before any write happens.
 *
 * @api
 */
final class BimaajiInstallCommand
{
    /** @var array<string, ClientTransformerInterface> */
    private readonly array $transformersByClientId;

    /**
     * @param iterable<ClientTransformerInterface> $transformers
     */
    public function __construct(
        iterable $transformers,
        private readonly SkillSetParser $skillSetParser,
    ) {
        $map = [];
        foreach ($transformers as $transformer) {
            $map[$transformer->clientId()] = $transformer;
        }
        ksort($map);
        $this->transformersByClientId = $map;
    }

    public function execute(\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int
    {
        $clients = $this->resolveClients($io);
        if ($clients === null) {
            return 1;
        }

        $projectRoot = realpath((string) getcwd());
        if ($projectRoot === false) {
            $io->error('bimaaji:install: cannot resolve project root via getcwd().');
            return 1;
        }

        $dryRun = (bool) $io->option('dry-run');
        $force = (bool) $io->option('force');

        try {
            $skills = $this->skillSetParser->parse();
        } catch (SkillResourceException $exception) {
            // The diagnostic already names the resolved absolute directory
            // and the remedy for this failure class (missing vs corrupt).
            $io->error('bimaaji:install: ' . $exception->getMessage());
            return 1;
        }

        $exitCode = 0;
        $manifest = InstalledManifest::load($projectRoot);
        $manifestChanged = false;

        foreach ($clients as $clientId) {
            $transformer = $this->resolveTransformer($io, $clientId);
            if ($transformer === null) {
                $exitCode = 1;
                continue;
            }

            $summary = $this->installForClient(
                io: $io,
                transformer: $transformer,
                skills: $skills,
                projectRoot: $projectRoot,
                dryRun: $dryRun,
                force: $force,
                recorded: $manifest->targetsFor($clientId),
            );

            $io->writeln(sprintf(
                'Client %s: %d written, %d unchanged, %d skipped.',
                $clientId,
                $summary['written'],
                $summary['unchanged'],
                $summary['skipped'],
            ));

            if ($summary['pruned'] > 0 || $summary['retired'] > 0 || $summary['released'] > 0) {
                $io->writeln(sprintf(
                    'Client %s: %d retired target(s) removed, %d neutralised, %d released.',
                    $clientId,
                    $summary['pruned'],
                    $summary['retired'],
                    $summary['released'],
                ));
            }

            if (!$dryRun) {
                $manifest = $manifest->withClient($clientId, $summary['owned']);
                $manifestChanged = true;
            }

            if ($summary['errors'] > 0) {
                $exitCode = 1;
            }
        }

        if ($manifestChanged) {
            $this->writeManifest($io, $projectRoot, $manifest);
        }

        return $exitCode;
    }

    /**
     * @return list<string>|null
     */
    private function resolveClients(\Waaseyaa\CLI\Command\SymfonyCommandIO $io): ?array
    {
        $rawClients = $io->option('client');
        $clients = [];
        if (is_array($rawClients)) {
            foreach ($rawClients as $entry) {
                $clients = array_merge($clients, $this->splitCsv((string) $entry));
            }
        } elseif (is_string($rawClients) && $rawClients !== '') {
            $clients = $this->splitCsv($rawClients);
        }

        if ($clients !== []) {
            return $this->normaliseClientList($clients);
        }

        if (!$io->isInteractive()) {
            $io->error('bimaaji:install: --client is required when stdin is non-TTY. Pass --client=<id>[,<id>...] or run interactively.');
            return null;
        }

        $available = implode(', ', array_keys($this->transformersByClientId));
        $answer = $io->ask(sprintf('Install for which client(s)? (comma-separated; available: %s)', $available));

        if ($answer === null || trim($answer) === '') {
            $io->error('bimaaji:install: no clients selected; nothing to do.');
            return null;
        }

        return $this->normaliseClientList($this->splitCsv($answer));
    }

    private function resolveTransformer(\Waaseyaa\CLI\Command\SymfonyCommandIO $io, string $clientId): ?ClientTransformerInterface
    {
        if (isset($this->transformersByClientId[$clientId])) {
            return $this->transformersByClientId[$clientId];
        }

        $available = array_keys($this->transformersByClientId);
        $suggestion = $this->nearestClient($clientId, $available);
        $message = sprintf('bimaaji:install: unknown client "%s".', $clientId);
        if ($suggestion !== null) {
            $message .= sprintf(' Did you mean "%s"?', $suggestion);
        }
        $message .= sprintf(' Available: %s.', implode(', ', $available));

        $io->error($message);
        return null;
    }

    /**
     * @param list<ParsedSkill> $skills
     * @param array<string, string> $recorded Paths this command previously wrote for this client, mapped to the sha1 it left on disk.
     * @return array{written: int, unchanged: int, skipped: int, errors: int, pruned: int, retired: int, released: int, owned: array<string, string>}
     */
    private function installForClient(
        \Waaseyaa\CLI\Command\SymfonyCommandIO $io,
        ClientTransformerInterface $transformer,
        array $skills,
        string $projectRoot,
        bool $dryRun,
        bool $force,
        array $recorded,
    ): array {
        $written = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = 0;

        /** @var array<string, string> $owned relative path => sha1 of the bytes now on disk */
        $owned = [];

        $targets = $transformer->targetFiles($skills);

        // The current target set is what the transformer DECLARES, not what
        // this run managed to write. Deriving it from the write results would
        // make a transient failure — a permission error, a refused overwrite,
        // a sandbox rejection — look like an upstream removal, and the pruner
        // would then delete a file that is still very much current. It would
        // also make every `--dry-run` report the whole write set as retired.
        $declaredPaths = array_map(static fn(TargetFile $file): string => $file->path, $targets);

        foreach ($targets as $file) {
            $resolved = $this->resolveAndAssertInSandbox($io, $file, $projectRoot);
            if ($resolved === null) {
                $skipped++;
                $errors++;
                continue;
            }

            $existing = is_file($resolved) ? @file_get_contents($resolved) : false;

            // Marker-bounded refresh: when the existing file already carries
            // a managed region, the payload we write is that file with only
            // its region replaced. Everything outside the markers is carried
            // through byte-for-byte, so the sha1 idempotency compare and the
            // overwrite prompt below both operate on the real write set.
            $payload = $file->content;
            $spliced = false;
            if ($existing !== false) {
                $merged = ManagedRegion::splice($existing, $file->content);
                if ($merged !== null) {
                    $payload = $merged;
                    $spliced = true;
                }
            }

            if ($existing !== false && sha1($existing) === sha1($payload)) {
                $unchanged++;
                $owned[$file->path] = sha1($existing);
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf(
                    '[DRY-RUN] would %s %s (%d bytes from skill=%s)',
                    $spliced ? 'refresh the managed region of' : 'write',
                    $file->path,
                    strlen($payload),
                    $file->sourceSkill ?? '<aggregate>',
                ));
                $written++;
                continue;
            }

            // A marker-bounded refresh never touches hand-authored content,
            // so it does not need --force or a confirmation. Only a file we
            // cannot recognise falls back to the overwrite contract.
            if ($existing !== false && !$spliced && !$force) {
                if (!$io->isInteractive()) {
                    $io->error(sprintf(
                        'bimaaji:install: %s exists, carries no `%s` marker, and differs; pass --force to overwrite '
                        . 'or --dry-run to preview. Adding the marker pair around the framework block makes future '
                        . 'runs refresh only that region.',
                        $file->path,
                        ManagedRegion::BEGIN,
                    ));
                    $skipped++;
                    $errors++;
                    continue;
                }
                if (!$io->confirm(sprintf('Overwrite %s?', $file->path), default: false)) {
                    $skipped++;
                    continue;
                }
            }

            if (!$this->writeFile($resolved, $payload, $io)) {
                $skipped++;
                $errors++;
                continue;
            }

            $written++;
            $owned[$file->path] = sha1($payload);
        }

        // A declared target this run could not write keeps whatever ownership
        // record it already had. Dropping it would quietly disown a file that
        // still exists and is still current, so a later run could no longer
        // prune it when it really is retired.
        foreach ($declaredPaths as $declaredPath) {
            if (!isset($owned[$declaredPath]) && isset($recorded[$declaredPath])) {
                $owned[$declaredPath] = $recorded[$declaredPath];
            }
        }

        $prune = $this->pruneRetiredTargets(
            io: $io,
            projectRoot: $projectRoot,
            recorded: $recorded,
            currentPaths: $declaredPaths,
            dryRun: $dryRun,
        );

        return [
            'written' => $written,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'errors' => $errors,
            'pruned' => $prune['pruned'],
            'retired' => $prune['retired'],
            'released' => $prune['released'],
            'owned' => $owned,
        ];
    }

    /**
     * Remove targets this command generated in an earlier run that the
     * current skill set no longer produces — an upstream removal or rename.
     *
     * Ownership is read from the manifest, never inferred from the filename:
     * a path absent from `$recorded` is not touched, whatever it is called.
     * Three outcomes, in descending confidence:
     *
     * - The bytes still match what we wrote → nobody has touched it, delete
     *   it, then remove its directory if that leaves it empty. A skill
     *   directory holding supporting files the user added stays.
     * - The bytes differ but the file still carries a marker pair → ours,
     *   but edited. Deleting would take hand-authored bytes with it, so the
     *   managed region is replaced with a retirement notice and everything
     *   outside the markers is preserved byte-for-byte.
     * - The bytes differ and there is no marker pair → ownership can no
     *   longer be demonstrated. Leave the file completely alone and release
     *   the claim, so no future run touches it either.
     *
     * @param array<string, string> $recorded relative path => sha1 previously written
     * @param list<string> $currentPaths paths the current run produced
     * @return array{pruned: int, retired: int, released: int}
     */
    private function pruneRetiredTargets(
        \Waaseyaa\CLI\Command\SymfonyCommandIO $io,
        string $projectRoot,
        array $recorded,
        array $currentPaths,
        bool $dryRun,
    ): array {
        $pruned = 0;
        $retired = 0;
        $released = 0;

        $obsolete = array_diff_key($recorded, array_flip($currentPaths));
        ksort($obsolete);

        foreach ($obsolete as $obsoletePath => $recordedSha1) {
            $resolved = $this->resolveAndAssertInSandbox(
                $io,
                new TargetFile(path: $obsoletePath, content: '', sourceSkill: null),
                $projectRoot,
            );
            if ($resolved === null) {
                // Sandbox rejection already reported; never act on it.
                $released++;
                continue;
            }

            if (!is_file($resolved)) {
                // Already gone. Nothing to remove, nothing to warn about.
                $pruned++;
                continue;
            }

            $contents = @file_get_contents($resolved);
            if ($contents === false) {
                $io->error(sprintf(
                    'bimaaji:install: cannot read the retired target %s to verify ownership; leaving it in place.',
                    $obsoletePath,
                ));
                $released++;
                continue;
            }

            if (sha1($contents) === $recordedSha1) {
                if ($dryRun) {
                    $io->writeln(sprintf('[DRY-RUN] would remove retired target %s', $obsoletePath));
                    $pruned++;
                    continue;
                }
                if (!@unlink($resolved)) {
                    $io->error(sprintf('bimaaji:install: failed to remove the retired target %s.', $obsoletePath));
                    $released++;
                    continue;
                }
                // Only the file's own directory, only when empty — a skill
                // directory carrying the user's supporting files survives.
                @rmdir(dirname($resolved));
                $io->writeln(sprintf('Removed retired target %s (unmodified since it was generated).', $obsoletePath));
                $pruned++;
                continue;
            }

            $notice = ManagedRegion::splice(
                $contents,
                ManagedRegion::retirementNotice(sprintf(
                    'The skill that generated `%s` is no longer part of the framework skill set.',
                    $obsoletePath,
                )),
            );
            if ($notice === null) {
                $io->writeln(sprintf(
                    'Left %s in place: it was generated by an earlier run but has since been rewritten without the '
                    . '`%s` marker, so ownership can no longer be demonstrated. Delete it by hand if it is stale.',
                    $obsoletePath,
                    ManagedRegion::BEGIN,
                ));
                $released++;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf(
                    '[DRY-RUN] would neutralise the managed region of retired target %s (your edits outside it are kept)',
                    $obsoletePath,
                ));
                $retired++;
                continue;
            }

            if (!$this->writeFile($resolved, $notice, $io)) {
                $released++;
                continue;
            }

            $io->writeln(sprintf(
                'Retired the managed region of %s; your content outside the markers was preserved.',
                $obsoletePath,
            ));
            $retired++;
        }

        return ['pruned' => $pruned, 'retired' => $retired, 'released' => $released];
    }

    private function writeManifest(
        \Waaseyaa\CLI\Command\SymfonyCommandIO $io,
        string $projectRoot,
        InstalledManifest $manifest,
    ): void {
        $target = $projectRoot . DIRECTORY_SEPARATOR . InstalledManifest::RELATIVE_PATH;
        $json = $manifest->toJson();

        $existing = is_file($target) ? @file_get_contents($target) : false;
        if ($existing !== false && $existing === $json) {
            return;
        }

        if (!$this->writeFile($target, $json, $io)) {
            // Not fatal for the install itself, but the next run cannot
            // prune what this one wrote — say so rather than failing quietly.
            $io->error(sprintf(
                'bimaaji:install: the generated files were written but the ownership manifest %s could not be '
                . 'updated. A later run will not be able to prune them.',
                InstalledManifest::RELATIVE_PATH,
            ));
        }
    }

    private function resolveAndAssertInSandbox(\Waaseyaa\CLI\Command\SymfonyCommandIO $io, TargetFile $file, string $projectRoot): ?string
    {
        if ($file->path === '' || $this->isAbsolutePath($file->path) || str_contains($file->path, '..')) {
            $io->error(sprintf(
                'bimaaji:install: rejected suspicious target path %s (absolute or contains ..).',
                $file->path,
            ));
            return null;
        }

        $intended = $projectRoot . DIRECTORY_SEPARATOR . $file->path;

        // The textual guard above already blocks `..` and absolute paths, so the
        // would-be target is textually inside $projectRoot. Only do a realpath
        // check on the *nearest existing ancestor* — that catches symlink-based
        // escapes (e.g. someone replaced a project subdirectory with a symlink
        // pointing outside the root) without rejecting on healthy ancestors
        // that legitimately sit above the project root (`/`, `/home`, etc.).
        $existingAncestor = $this->findNearestExistingAncestor(dirname($intended));
        if ($existingAncestor !== null) {
            $resolved = realpath($existingAncestor);
            if ($resolved === false || !str_starts_with($resolved . DIRECTORY_SEPARATOR, $projectRoot . DIRECTORY_SEPARATOR)) {
                $io->error(sprintf(
                    'bimaaji:install: rejected target outside project root: %s resolves outside project root (project root: %s).',
                    $file->path,
                    $projectRoot,
                ));
                return null;
            }
        }

        return $intended;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function findNearestExistingAncestor(string $path): ?string
    {
        while ($path !== '' && $path !== DIRECTORY_SEPARATOR && $path !== '.') {
            if (is_dir($path)) {
                return $path;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return null;
    }

    private function writeFile(string $absolutePath, string $contents, \Waaseyaa\CLI\Command\SymfonyCommandIO $io): bool
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $io->error(sprintf('bimaaji:install: failed to create directory %s.', $directory));
            return false;
        }

        $bytes = @file_put_contents($absolutePath, $contents);
        if ($bytes === false) {
            $io->error(sprintf('bimaaji:install: failed to write %s.', $absolutePath));
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitCsv(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn(string $piece): bool => $piece !== ''));
    }

    /**
     * @param list<string> $clients
     * @return list<string>
     */
    private function normaliseClientList(array $clients): array
    {
        $deduped = [];
        foreach ($clients as $client) {
            $key = strtolower($client);
            if (!isset($deduped[$key])) {
                $deduped[$key] = $key;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param list<string> $available
     */
    private function nearestClient(string $candidate, array $available): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($available as $option) {
            $distance = levenshtein($candidate, $option);
            if ($distance < $bestDistance && $distance <= 3) {
                $bestDistance = $distance;
                $best = $option;
            }
        }

        return $best;
    }
}
