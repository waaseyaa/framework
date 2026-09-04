<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;

/** @api */
final readonly class SiteDoctorHandler
{
    public function __construct(private string $defaultProjectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if (!(bool) $io->option('strict')) {
            $io->error('site:doctor currently requires --strict; non-strict diagnostics cannot certify a site.');

            return 2;
        }
        $root = trim((string) ($io->option('project-root') ?? $this->defaultProjectRoot));
        $format = trim((string) ($io->option('format') ?? 'text'));
        if (!in_array($format, ['text', 'json'], true)) {
            $io->error('site:doctor --format must be text or json.');

            return 2;
        }
        try {
            $report = new SiteDoctorService()->inspectUnits($root);
        } catch (SiteManifestValidationException $exception) {
            $violation = $exception->violations[0];
            $io->error(sprintf('%s at %s: %s', $violation->code, $violation->path, $violation->message));

            return 2;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return 2;
        }
        if ($format === 'json') {
            $io->writeln($report->canonicalJson());
        } else {
            $io->writeln(sprintf('%s — strict site doctor (%d finding%s, %d suppressed)', $report->summary, count($report->findings), count($report->findings) === 1 ? '' : 's', count($report->suppressed)));
            foreach ($report->findings as $finding) {
                $io->writeln(sprintf('%s %s:%d — %s', $finding->id, $finding->path, $finding->line, $finding->message));
                $io->writeln('  Remediation: ' . $finding->remediation);
            }
        }

        return $report->exitCode();
    }
}
