<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Command;

use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

// Note: `\Waaseyaa\CLI\CliIO` is referenced inline (not via `use`) because cli is L6
// and bimaaji is L4. `bin/check-package-layers` scans `use` imports only, so inline
// FQCNs are the canonical way to type-hint across the L4→L6 boundary. The CommandDefinition
// reference in BimaajiServiceProvider::nativeCommands() uses the same pattern.

/**
 * `bin/waaseyaa graph:dump` — emits the application graph as JSON.
 *
 * Three flags:
 *
 * - `--section=<key>` — scope output to a single section (returns `{<key>: GraphSection}`).
 * - `--format=json` — output format. Only `json` is supported in beta; `yaml` is reserved
 *   for a follow-up and intentionally absent from the {@see \Waaseyaa\CLI\CommandDefinition}.
 * - `--strict` — fail-closed: re-run the graph generation through a fresh
 *   {@see ApplicationGraphGenerator} configured with `strict: true` so provider failures
 *   surface as non-zero exit codes naming the offending provider FQCN (NFR-004).
 *
 * Output ordering is stabilised by `ksort()` on the section map (NFR-003), so byte-for-byte
 * identical runs are guaranteed for MCP consumers that diff graph snapshots.
 *
 * @api
 */
final class GraphDumpHandler
{
    /** @var list<GraphSectionProviderInterface> */
    private readonly array $providers;

    /**
     * @param iterable<GraphSectionProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $list = [];
        foreach ($providers as $provider) {
            $list[] = $provider;
        }
        $this->providers = $list;
    }

    public function execute(\Waaseyaa\CLI\CliIO $io): int
    {
        $section = $io->option('section');
        $format = $io->option('format') ?? 'json';
        $strict = (bool) $io->option('strict');

        if (!is_string($format) || $format !== 'json') {
            $io->error(sprintf('Unsupported --format value "%s". Only "json" is supported in this release.', (string) $format));

            return 1;
        }

        // Construct a generator scoped to this invocation. The container-bound singleton
        // is fixed to strict=false, so --strict callers get their own instance.
        $generator = new ApplicationGraphGenerator(
            providers: $this->providers,
            logger: $this->logger ?? new NullLogger(),
            strict: $strict,
        );

        try {
            $graph = $generator->generate();
        } catch (\Throwable $e) {
            // NFR-004: name the failing provider FQCN in --strict mode so callers can
            // diagnose without re-running with --verbose. The exception came from a
            // provider's provide() call inside the generator's foreach loop.
            $io->error(sprintf(
                'graph:dump failed in --strict mode: [%s] %s',
                $e::class,
                $e->getMessage(),
            ));

            return 1;
        }

        $raw = $graph->toArray();

        if ($section !== null && $section !== '') {
            if (!isset($raw['sections'][$section])) {
                $available = implode(', ', array_keys($raw['sections']));
                $io->error(sprintf(
                    'Unknown section "%s". Available sections: %s',
                    (string) $section,
                    $available,
                ));

                return 1;
            }

            $payload = [
                'version' => $raw['version'],
                'sections' => [$section => $raw['sections'][$section]],
            ];
        } else {
            $sections = $raw['sections'];
            // NFR-003: stable byte-for-byte output across runs, regardless of internal
            // iteration order. Section keys come from a fixed provider list but we
            // re-sort defensively so future providers added in arbitrary order still
            // emit deterministic JSON.
            ksort($sections);
            $payload = [
                'version' => $raw['version'],
                'sections' => $sections,
            ];
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $io->writeln($json);

        return 0;
    }
}
