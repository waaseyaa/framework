<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Ai;

use Waaseyaa\AI\Agent\Reaper\StalledRunReaper;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * `bin/waaseyaa ai:reap-stalled-runs [--max-runtime-seconds=<int>]` —
 * flip stalled `running` rows to terminal `failed/worker_crashed`
 * (FR-007, NFR-004).
 *
 * Thin wrapper around {@see StalledRunReaper::reap()}. The reaper is
 * the canonical surface — this command exists so cron / `schedule:run`
 * can call it as `ai:reap-stalled-runs`.
 *
 * Idempotent: a second invocation in the same tick window reaps zero.
 *
 * Exit code: 0 on success.
 *
 * @api
 */
final class AiReapStalledRunsCommand
{
    public function __construct(
        private readonly StalledRunReaper $reaper,
        private readonly int $defaultMaxRuntimeSeconds = 600,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $maxRuntimeSeconds = $this->resolveMaxRuntimeSeconds($io);
        if ($maxRuntimeSeconds === null) {
            return 1;
        }

        try {
            $reaped = $this->reaper->reap($maxRuntimeSeconds);
        } catch (\Throwable $e) {
            $io->error(\sprintf('ai:reap-stalled-runs: %s', $e->getMessage()));
            return 1;
        }

        $io->writeln(\sprintf('Reaped %d stalled runs.', $reaped));

        return 0;
    }

    private function resolveMaxRuntimeSeconds(SymfonyCommandIO $io): ?int
    {
        $option = $io->option('max-runtime-seconds');
        if ($option === null || $option === '' || $option === false) {
            return $this->defaultMaxRuntimeSeconds;
        }
        if (\is_int($option)) {
            $value = $option;
        } elseif (\is_string($option) && ctype_digit($option)) {
            $value = (int) $option;
        } else {
            $io->error(\sprintf(
                'ai:reap-stalled-runs: --max-runtime-seconds must be a positive integer; got "%s".',
                \is_scalar($option) ? (string) $option : gettype($option),
            ));
            return null;
        }
        if ($value <= 0) {
            $io->error(\sprintf(
                'ai:reap-stalled-runs: --max-runtime-seconds must be > 0; got %d.',
                $value,
            ));
            return null;
        }
        return $value;
    }
}
