<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Command;

use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Install\TargetFile;

// Note: `\Waaseyaa\CLI\CliIO` is referenced inline (not via `use`) because cli is L6
// and bimaaji is L4. `bin/check-package-layers` scans `use` imports only, so inline
// FQCNs are the canonical way to type-hint across the L4→L6 boundary. The
// CommandDefinition reference in BimaajiServiceProvider::nativeCommands() uses the
// same pattern (mirrors GraphDumpHandler).

/**
 * `bin/waaseyaa bimaaji:install` — install Waaseyaa framework guidelines + skills
 * into a consuming project for one or more agent clients.
 *
 * Surfaces the framework's `skills/waaseyaa/*` skill set through per-client
 * {@see ClientTransformerInterface} implementations (Claude Code, Cursor,
 * Codex, Copilot, Gemini, Windsurf, Junie) and writes the resulting target
 * files to the project root.
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
 * compare and counted as `unchanged` in the per-client summary. Sandbox
 * discipline (NFR-002): every target path must resolve under the project
 * root before any write happens.
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

    public function execute(\Waaseyaa\CLI\CliIO $io): int
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

        $skills = $this->skillSetParser->parse();
        if ($skills === []) {
            $io->error('bimaaji:install: no skills discovered. The skill source directory is empty or missing — run from a project with `skills/waaseyaa/<id>/SKILL.md` files or configure `bimaaji.skills_directory`.');
            return 1;
        }

        $exitCode = 0;

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
            );

            $io->writeln(sprintf(
                'Client %s: %d written, %d unchanged, %d skipped.',
                $clientId,
                $summary['written'],
                $summary['unchanged'],
                $summary['skipped'],
            ));

            if ($summary['errors'] > 0) {
                $exitCode = 1;
            }
        }

        return $exitCode;
    }

    /**
     * @return list<string>|null
     */
    private function resolveClients(\Waaseyaa\CLI\CliIO $io): ?array
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

    private function resolveTransformer(\Waaseyaa\CLI\CliIO $io, string $clientId): ?ClientTransformerInterface
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
     * @return array{written: int, unchanged: int, skipped: int, errors: int}
     */
    private function installForClient(
        \Waaseyaa\CLI\CliIO $io,
        ClientTransformerInterface $transformer,
        array $skills,
        string $projectRoot,
        bool $dryRun,
        bool $force,
    ): array {
        $written = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($transformer->targetFiles($skills) as $file) {
            $resolved = $this->resolveAndAssertInSandbox($io, $file, $projectRoot);
            if ($resolved === null) {
                $skipped++;
                $errors++;
                continue;
            }

            $existing = is_file($resolved) ? @file_get_contents($resolved) : false;
            if ($existing !== false && sha1($existing) === sha1($file->content)) {
                $unchanged++;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf(
                    '[DRY-RUN] would write %s (%d bytes from skill=%s)',
                    $file->path,
                    strlen($file->content),
                    $file->sourceSkill ?? '<aggregate>',
                ));
                $written++;
                continue;
            }

            if ($existing !== false && !$force) {
                if (!$io->isInteractive()) {
                    $io->error(sprintf(
                        'bimaaji:install: %s exists and differs; pass --force to overwrite or --dry-run to preview.',
                        $file->path,
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

            if (!$this->writeFile($resolved, $file->content, $io)) {
                $skipped++;
                $errors++;
                continue;
            }

            $written++;
        }

        return ['written' => $written, 'unchanged' => $unchanged, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function resolveAndAssertInSandbox(\Waaseyaa\CLI\CliIO $io, TargetFile $file, string $projectRoot): ?string
    {
        if ($file->path === '' || str_starts_with($file->path, '/') || str_contains($file->path, '..')) {
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

    private function writeFile(string $absolutePath, string $contents, \Waaseyaa\CLI\CliIO $io): bool
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
