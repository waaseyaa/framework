<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteManifestWizard;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final readonly class SiteInitHandler
{
    public function __construct(private string $defaultProjectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $projectRoot = trim((string) ($io->option('project-root') ?? $this->defaultProjectRoot));
        $answers = trim((string) ($io->option('answers') ?? ''));
        $dryRun = (bool) $io->option('dry-run');
        $yes = (bool) $io->option('yes');

        try {
            if ($answers !== '') {
                $answerPath = $this->resolveAnswerPath($answers, $projectRoot);
                if (!is_file($answerPath)) {
                    throw new \InvalidArgumentException("Answer document does not exist: {$answers}");
                }
                $yaml = file_get_contents($answerPath);
                if ($yaml === false) {
                    throw new \RuntimeException("Unable to read answer document: {$answers}");
                }
            } elseif ($io->isInteractive()) {
                $yaml = new SiteManifestWizard()->create($io, $projectRoot);
            } else {
                $io->error('Non-interactive site:init requires a complete --answers document.');

                return 2;
            }

            $manifest = new SiteManifestParser()->parse($yaml, $answers !== '' ? $answers : '<interactive>');
            $site = SiteArtifactRendererFactory::create()->render($manifest);
            $service = new SiteInitializationService($projectRoot);
            if ($dryRun) {
                $plan = $service->initialize($site, true);
                foreach ($plan->changedPaths as $path) {
                    $io->writeln('  ' . $path);
                }
                $io->writeln(sprintf('Would initialize %d generated artifacts.', count($plan->changedPaths)));

                return 0;
            }
            if (!$yes && !$io->isInteractive()) {
                $io->error('Non-interactive publication requires --yes after reviewing --dry-run output.');

                return 2;
            }
            $authorize = function (array $changedPaths) use ($io, $yes): bool {
                foreach ($changedPaths as $path) {
                    $io->writeln('  ' . $path);
                }

                return $yes || $io->confirm('Publish this complete generated site contract?', false);
            };
            $result = $service->initialize($site, authorize: $authorize);
            if ($result->recoveredInterruptedTransaction) {
                $io->writeln('Recovered an interrupted site initialization transaction before publication.');
            }
            if ($result->cancelled) {
                $io->writeln('Site initialization cancelled without publishing new artifacts.');

                return 1;
            }
            $io->writeln(sprintf('Initialized %d generated artifacts.', count($result->changedPaths)));
            if ($result->cleanupPending) {
                $io->writeln('Initialization committed successfully; transaction cleanup is pending and will be retried before the next run.');
            }

            return 0;
        } catch (SiteManifestValidationException $exception) {
            $violation = $exception->violations[0];
            $io->error(sprintf('%s at %s: %s', $violation->code, $violation->path, $violation->message));
        } catch (SiteInitializationCollisionException|SiteInitializationLockedException|\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());
        }

        return 2;
    }

    private function resolveAnswerPath(string $answers, string $projectRoot): string
    {
        if (str_starts_with($answers, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $answers) === 1) {
            return $answers;
        }

        return rtrim($projectRoot, '/\\') . '/' . $answers;
    }
}
