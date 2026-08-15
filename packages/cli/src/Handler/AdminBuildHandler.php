<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\AdminBuild\AdminBuildArtifactScanException;
use Waaseyaa\CLI\AdminBuild\AdminBuildPolicyException;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessException;
use Waaseyaa\CLI\AdminBuild\HermeticAdminBuildPipeline;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Support\AdminPackagePathResolver;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

/** CLI command handler resolved through the provider command registry. @api */
final class AdminBuildHandler
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly HermeticAdminBuildPipeline $pipeline = new HermeticAdminBuildPipeline(),
        private readonly RedactorProcessor $sanitizer = new RedactorProcessor(),
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $adminPath = new AdminPackagePathResolver($this->projectRoot)->resolve();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Admin package: %s', $adminPath));
        try {
            /** @var array<string, string> $parentEnvironment */
            $parentEnvironment = getenv();
            $report = $this->pipeline->run(
                projectRoot: $this->projectRoot,
                adminPath: $adminPath,
                parentEnvironment: $parentEnvironment,
                sanitizer: $this->sanitizer,
                stdout: static function (string $text) use ($io): void {
                    $io->write($text);
                },
                stderr: static function (string $text) use ($io): void {
                    $io->error(rtrim($text, "\r\n"));
                },
            );
        } catch (AdminBuildPolicyException|AdminBuildProcessException|AdminBuildArtifactScanException $e) {
            $io->error(sprintf('%s [%s]', $e->getMessage(), $e->errorCode));

            return 1;
        }

        $io->writeln(sprintf(
            'Admin build verified: %d inventoried files; evidence %s.',
            count($report->files),
            $report->inventoryHash,
        ));

        return 0;
    }
}
