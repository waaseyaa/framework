<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\CLI\Site\SiteInitializationResult;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteManifestWizard;
use Waaseyaa\CLI\Site\SitePreset;
use Waaseyaa\CLI\Site\SitePresetResolver;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratorFeatureNegotiation;
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
        $blueprintInvocation = false;

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
            $blueprintInvocation = $manifest->applicationBlueprint !== null;
            GeneratorFeatureNegotiation::assert($manifest, SiteArtifactRendererFactory::advertisedGeneratorFeatures(), 'site:init');
            $decisionPath = trim((string) ($io->option('decision-receipt') ?? ''));
            $decisionReceipt = $decisionPath === '' ? null : $this->readDecisionReceipt($decisionPath, $projectRoot);
            $site = $manifest->applicationBlueprint === null
                ? SiteArtifactRendererFactory::create()->render($manifest)
                : ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            $service = new SiteInitializationService($projectRoot);
            if ($dryRun) {
                $plan = $service->initialize($site, true, decisionReceipt: $decisionReceipt);
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
            $result = $service->initialize($site, authorize: $authorize, decisionReceipt: $decisionReceipt);
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
            $this->writeError($io, sprintf('%s at %s: %s', $violation->code, $violation->path, $violation->message), $json, code: $violation->code === 'SITE050_DECISION_RECEIPT_INVALID' ? $violation->code : null, pointer: $violation->path);
        } catch (GenerationRefusalException $exception) {
            // Blueprint activation makes compiler and approval refusals part
            // of the coded public contract. A blueprint-free engine refusal
            // retains the legacy message-only JSON envelope.
            if ($exception->source === 'site:init' || $blueprintInvocation) {
                $this->writeCodedError($io, $exception, $json);
            } else {
                $this->writeError($io, $exception->getMessage(), $json);
            }
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
        $io->writeRaw(CanonicalJson::encode([
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
        ]) . "\n");
    }

    /** @param list<ChangeReceipt> $receipts */
    private function writeError(SymfonyCommandIO $io, string $message, bool $json, array $receipts = [], ?ArtifactApplyResult $result = null, ?string $code = null, ?string $pointer = null): void
    {
        if ($json) {
            $error = $code === null ? ['message' => $message] : ['code' => $code, 'pointer' => $pointer, 'message' => $message];
            $io->writeRaw(CanonicalJson::encode(['evaluation' => null, 'result' => $result?->toArray(), 'receipts' => array_map(static fn(ChangeReceipt $receipt): array => $receipt->toArray(), $receipts), 'errors' => [$error]]) . "\n");

            return;
        }
        $io->error($message);
    }

    /**
     * A coded refusal (e.g. `GEN007_UNSUPPORTED_DECLARATION` from generator
     * feature negotiation) widens the `errors` entry to carry `code`,
     * `pointer` and `message` from {@see GenerationRefusalException::toArray()}
     * instead of `message` alone. The envelope's other members are unchanged
     * from an uncoded refusal: `evaluation`, `result` and `receipts` stay
     * `null`/`null`/`[]`, since a coded refusal at this boundary precedes
     * every lock, journal and write, identically in dry-run and apply.
     * Pure compilation may already have produced a reviewable plan.
     */
    private function writeCodedError(SymfonyCommandIO $io, GenerationRefusalException $exception, bool $json): void
    {
        if ($json) {
            $io->writeRaw(CanonicalJson::encode(['evaluation' => null, 'result' => null, 'receipts' => [], 'errors' => $exception->toArray()]) . "\n");

            return;
        }
        $violation = $exception->violations[0];
        $location = $violation->path ?? $violation->pointer;
        $io->error($location === null
            ? sprintf('%s: %s', $violation->code->value, $violation->message)
            : sprintf('%s at %s: %s', $violation->code->value, $location, $violation->message));
    }

    private function readDecisionReceipt(string $receiptPath, string $projectRoot): BlueprintDecisionReceipt
    {
        try {
            $path = $this->resolveAnswerPath($receiptPath, $projectRoot);
            if (!is_file($path) || !is_readable($path)) {
                throw new \InvalidArgumentException('The decision receipt must be a readable JSON document.');
            }
            // Read exactly once: a later path replacement cannot change the
            // immutable approval snapshot used by this invocation.
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \InvalidArgumentException('The decision receipt could not be read.');
            }
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException('The decision receipt must be a JSON object.');
            }

            return BlueprintDecisionReceipt::fromArray($document, $receiptPath);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            throw new SiteManifestValidationException($receiptPath, [new ManifestViolation(
                'SITE050_DECISION_RECEIPT_INVALID',
                '/decision_receipt',
                'Expected a valid closed blueprint decision receipt JSON document.',
            )], $exception);
        }
    }

    private function resolveAnswerPath(string $answers, string $projectRoot): string
    {
        if (str_starts_with($answers, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $answers) === 1) {
            return $answers;
        }

        return rtrim($projectRoot, '/\\') . '/' . $answers;
    }
}
