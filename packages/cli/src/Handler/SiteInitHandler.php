<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\CLI\Site\SiteInitializationResult;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteManifestWizard;
use Waaseyaa\CLI\Site\SitePreset;
use Waaseyaa\CLI\Site\SitePresetResolver;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final readonly class SiteInitHandler
{
    public function __construct(private string $defaultProjectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $projectRoot = trim((string) ($io->option('project-root') ?? $this->defaultProjectRoot));
        $answers = trim((string) ($io->option('answers') ?? ''));
        $presetOption = trim((string) ($io->option('preset') ?? ''));
        $dryRun = (bool) $io->option('dry-run');
        $yes = (bool) $io->option('yes');
        $json = (bool) $io->option('json');

        if ($json && $answers === '') {
            $this->writeError($io, 'site:init --json requires an --answers document.', true);

            return 2;
        }

        try {
            $preset = $presetOption === '' ? null : SitePreset::fromCliValue($presetOption);

            if ($answers !== '') {
                $answerPath = $this->resolveAnswerPath($answers, $projectRoot);
                if (!is_file($answerPath)) {
                    throw new \InvalidArgumentException("Answer document does not exist: {$answers}");
                }
                $document = file_get_contents($answerPath);
                if ($document === false) {
                    throw new \RuntimeException("Unable to read answer document: {$answers}");
                }
                $yaml = $preset === null
                    ? $document
                    : new SitePresetResolver()->resolveFromSeedDocument($preset, $document, $answers, $projectRoot);
            } elseif ($io->isInteractive()) {
                $yaml = $preset === null
                    ? new SiteManifestWizard()->create($io, $projectRoot)
                    : new SitePresetResolver()->resolveInteractively($preset, $io, $projectRoot);
            } elseif ($preset !== null) {
                $this->writeError($io, 'Non-interactive site:init with --preset requires a --answers seed document naming application identity and content types.', $json);

                return 2;
            } else {
                $this->writeError($io, 'Non-interactive site:init requires a complete --answers document.', $json);

                return 2;
            }

            $manifest = new SiteManifestParser()->parse($yaml, $answers !== '' ? $answers : '<interactive>');
            $site = SiteArtifactRendererFactory::create()->render($manifest);
            $service = new SiteInitializationService($projectRoot);
            if ($dryRun) {
                $plan = $service->initialize($site, true);
                if ($json) {
                    $this->writeStructuredResult($io, $plan);

                    return 0;
                }
                foreach ($plan->changedPaths as $path) {
                    $io->writeln('  ' . $path);
                }
                $io->writeln(sprintf('Would initialize %d generated artifacts.', count($plan->changedPaths)));

                return 0;
            }
            if (!$yes && (!$io->isInteractive() || $json)) {
                $this->writeError($io, 'Non-interactive publication requires --yes after reviewing --dry-run output.', $json);

                return 2;
            }
            $authorize = function (array $changedPaths) use ($io, $yes, $json): bool {
                if ($json) {
                    return true; // JSON publication already requires --yes above.
                }
                foreach ($changedPaths as $path) {
                    $io->writeln('  ' . $path);
                }

                return $yes || $io->confirm('Publish this complete generated site contract?', false);
            };
            $result = $service->initialize($site, authorize: $authorize);
            if ($json) {
                $this->writeStructuredResult($io, $result);

                return $result->cancelled ? 1 : 0;
            }
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
            $this->writeError($io, sprintf('%s at %s: %s', $violation->code, $violation->path, $violation->message), $json);
        } catch (SiteInitializationExecutionException $exception) {
            $this->writeError($io, $exception->getMessage(), $json, $exception->receipts, $exception->applyResult);
        } catch (SiteInitializationCollisionException|SiteInitializationLockedException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->writeError($io, $exception->getMessage(), $json);
        }

        return 2;
    }

    private function writeStructuredResult(SymfonyCommandIO $io, SiteInitializationResult $result): void
    {
        $evaluation = $result->evaluation;
        $io->writeln(CanonicalJson::encode([
            'evaluation' => $evaluation === null ? null : [
                'plan' => $evaluation->plan->toArray(),
                'project_state' => $evaluation->projectState->toArray(),
                'plan_digest' => $evaluation->planDigest,
                'project_state_digest' => $evaluation->projectStateDigest,
                'status' => $evaluation->status === [] ? new \stdClass() : array_map(static fn(ArtifactStatus $status): string => $status->value, $evaluation->status),
                'set_delta' => $evaluation->setDelta(),
                'refusals' => array_map(static fn(GenerationViolation $violation): array => $violation->toArray(), $evaluation->refusals),
            ],
            'result' => $result->applyResult?->toArray(),
            'receipts' => array_map(static fn(ChangeReceipt $receipt): array => $receipt->toArray(), $result->receipts),
        ]));
    }

    /** @param list<ChangeReceipt> $receipts */
    private function writeError(SymfonyCommandIO $io, string $message, bool $json, array $receipts = [], ?ArtifactApplyResult $result = null): void
    {
        if ($json) {
            $io->writeln(CanonicalJson::encode(['evaluation' => null, 'result' => $result?->toArray(), 'receipts' => array_map(static fn(ChangeReceipt $receipt): array => $receipt->toArray(), $receipts), 'errors' => [['message' => $message]]]));

            return;
        }
        $io->error($message);
    }

    private function resolveAnswerPath(string $answers, string $projectRoot): string
    {
        if (str_starts_with($answers, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $answers) === 1) {
            return $answers;
        }

        return rtrim($projectRoot, '/\\') . '/' . $answers;
    }
}
