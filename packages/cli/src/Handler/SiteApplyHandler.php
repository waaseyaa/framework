<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\DevelopmentInterruptionSeam;
use Waaseyaa\CLI\Site\Exception\DevelopmentInterruption;
use Waaseyaa\CLI\Site\Exception\SiteInitializationExecutionException;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;

/**
 * The installed second process of ADR-025 D-6.5 (#2789).
 *
 * A reviewed `waaseyaa.artifact_apply_request` document is decoded and executed
 * verbatim through the existing `SiteInitializationService::apply()` seam. This
 * command carries no compiler at all: a migration generator picks its target
 * filename from a clock reading at compile time, so recompiling here would
 * produce a different, equally valid plan and the operator's review would bind
 * nothing. The bytes it applies are the bytes that were reviewed, and the two
 * transported digests are the execution authority's `GEN005` self-check under
 * its exclusive lock — not this command's to verify early and lock-free.
 *
 * It stays on the boot-free command seam with `site:init` and `site:doctor`
 * (#2644): it needs only a project root, and the site-contract phase runs
 * before the framework has a database.
 *
 * @api
 */
final readonly class SiteApplyHandler
{
    public function __construct(private string $defaultProjectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $projectRoot = trim((string) ($io->option('project-root') ?? $this->defaultProjectRoot));
        $requestOption = trim((string) ($io->option('request') ?? ''));
        $json = (bool) $io->option('json');

        if ($requestOption === '') {
            $this->writeError($io, 'site:apply requires a --request document carrying the reviewed plan and its two digests.', $json);

            return 2;
        }

        // The seam is read only where it exists. Outside an explicit
        // development environment the option is not registered at all, and
        // re-reading the environment here means a stale or hand-built command
        // definition still cannot arm it.
        $interrupt = DevelopmentInterruptionSeam::isPermitted() && (bool) $io->option(DevelopmentInterruptionSeam::OPTION);

        try {
            $request = ArtifactApplyRequest::fromCanonicalJson($this->read($requestOption, $projectRoot), $requestOption);
            $invocation = new SiteInitializationService(
                $projectRoot,
                $interrupt ? DevelopmentInterruptionSeam::injector() : null,
            )->apply($request);
        } catch (DevelopmentInterruption $interruption) {
            // Deliberately reported, never repaired: the durable journal this
            // leaves behind is the evidence the next apply must recover.
            $this->writeError($io, $interruption->getMessage(), $json);

            return DevelopmentInterruptionSeam::EXIT_CODE;
        } catch (SiteManifestValidationException $exception) {
            $violation = $exception->violations[0];
            $this->writeError(
                $io,
                sprintf('%s at %s: %s', $violation->code, $violation->path, $violation->message),
                $json,
                code: $violation->code,
                pointer: $violation->path,
            );

            return 2;
        } catch (SiteInitializationExecutionException $exception) {
            $this->writeError($io, $exception->getMessage(), $json, $exception->receipts, $exception->applyResult);

            return 2;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->writeError($io, $exception->getMessage(), $json);

            return 2;
        }

        if ($json) {
            $io->writeRaw(CanonicalJson::encode([
                'result' => $invocation->result->toArray(),
                'receipts' => array_map(static fn(ChangeReceipt $receipt): array => $receipt->toArray(), $invocation->receipts),
                'errors' => [],
            ]) . "\n");
        } else {
            $this->writeResult($io, $invocation->result);
        }

        return $invocation->result->outcome === ArtifactApplyOutcome::Refused ? 2 : 0;
    }

    private function writeResult(SymfonyCommandIO $io, ArtifactApplyResult $result): void
    {
        if ($result->recoveredInterruptedTransaction) {
            $io->writeln('Recovered an interrupted site initialization transaction before publication.');
        }
        if ($result->outcome === ArtifactApplyOutcome::Refused) {
            foreach ($result->errors as $violation) {
                $io->error($this->describe($violation));
            }

            return;
        }
        foreach ($result->changed as $path) {
            $io->writeln('  ' . $path);
        }
        $io->writeln($result->outcome === ArtifactApplyOutcome::NoChanges
            ? 'The reviewed plan is already published; no artifacts changed.'
            : sprintf('Applied %d generated artifacts.', count($result->changed)));
        if ($result->cleanupPending) {
            $io->writeln('Publication committed successfully; transaction cleanup is pending and will be retried before the next run.');
        }
    }

    private function describe(GenerationViolation $violation): string
    {
        $location = $violation->path ?? $violation->pointer;

        return $location === null
            ? sprintf('%s: %s', $violation->code->value, $violation->message)
            : sprintf('%s at %s: %s', $violation->code->value, $location, $violation->message);
    }

    /**
     * The failure envelope carries the same three members as the success one.
     * `errors` reports a failure that produced no result at all — an
     * undecodable document, an unreadable path, a terminated execution — while
     * a governed refusal keeps its coded violations inside `result.errors`,
     * where the artifact-result document already publishes them. One authority
     * per question, exactly as `site:init --json` reports it.
     *
     * @param list<ChangeReceipt> $receipts
     */
    private function writeError(
        SymfonyCommandIO $io,
        string $message,
        bool $json,
        array $receipts = [],
        ?ArtifactApplyResult $result = null,
        ?string $code = null,
        ?string $pointer = null,
    ): void {
        if ($json) {
            $error = $code === null ? ['message' => $message] : ['code' => $code, 'pointer' => $pointer, 'message' => $message];
            $io->writeRaw(CanonicalJson::encode([
                'result' => $result?->toArray(),
                'receipts' => array_map(static fn(ChangeReceipt $receipt): array => $receipt->toArray(), $receipts),
                'errors' => [$error],
            ]) . "\n");

            return;
        }
        $io->error($message);
    }

    private function read(string $requestPath, string $projectRoot): string
    {
        $path = $this->resolve($requestPath, $projectRoot);
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("The apply request must be a readable canonical JSON document: {$requestPath}");
        }
        // Read exactly once: a later path replacement cannot change the
        // reviewed bytes this invocation decodes and executes.
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException("The apply request could not be read: {$requestPath}");
        }

        return $bytes;
    }

    /** Mirrors `SiteInitHandler`'s option-path resolution: absolute, or project-relative. */
    private function resolve(string $requestPath, string $projectRoot): string
    {
        if (str_starts_with($requestPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $requestPath) === 1) {
            return $requestPath;
        }

        return rtrim($projectRoot, '/\\') . '/' . $requestPath;
    }
}
