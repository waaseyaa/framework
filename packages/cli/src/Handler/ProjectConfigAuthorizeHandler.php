<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorizer;
use Waaseyaa\CLI\Site\SitePreset;
use Waaseyaa\CLI\Site\SitePresetResolver;
use Waaseyaa\SiteContract\SiteManifestParser;

/** Authoring-host command handler for a pre-initialization signed config bundle. @api */
final readonly class ProjectConfigAuthorizeHandler
{
    /** @var \Closure(): ?ProjectConfigAuthorizer */
    private \Closure $authorizer;

    /** @param callable(): ?ProjectConfigAuthorizer $authorizer */
    public function __construct(private string $defaultProjectRoot, callable $authorizer)
    {
        $this->authorizer = $authorizer(...);
    }

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $projectRoot = trim((string) ($io->option('project-root') ?? $this->defaultProjectRoot));
            $answers = trim((string) ($io->option('answers') ?? ''));
            $decision = trim((string) ($io->option('decision-receipt') ?? ''));
            if ($answers === '' || $decision === '') {
                throw new \InvalidArgumentException('project:config:authorize requires --answers and --decision-receipt documents.');
            }
            $expectedManifestDigest = $io->option('expected-site-manifest-digest');
            $expectedPlanDigest = $io->option('expected-site-plan-digest');
            if (($expectedManifestDigest === null) !== ($expectedPlanDigest === null)) {
                throw new \InvalidArgumentException(
                    '--expected-site-manifest-digest and --expected-site-plan-digest must be supplied together.',
                );
            }
            if ($expectedManifestDigest !== null && $expectedPlanDigest !== null) {
                if (!\is_string($expectedManifestDigest) || !\is_string($expectedPlanDigest)) {
                    throw new \InvalidArgumentException('Expected site identity options must be strings.');
                }
                ProjectConfigAuthorization::scope($expectedManifestDigest, $expectedPlanDigest);
            }
            $answerPath = $this->resolvePath($answers, $projectRoot);
            $answerBytes = is_file($answerPath) && !is_link($answerPath)
                ? file_get_contents($answerPath)
                : false;
            if (!\is_string($answerBytes)) {
                throw new \InvalidArgumentException(sprintf('Answer document does not exist or is unreadable: %s', $answers));
            }
            $presetValue = trim((string) ($io->option('preset') ?? ''));
            $yaml = $presetValue === ''
                ? $answerBytes
                : new SitePresetResolver()->resolveFromSeedDocument(
                    SitePreset::fromCliValue($presetValue),
                    $answerBytes,
                    $answers,
                    $projectRoot,
                );
            $manifest = new SiteManifestParser()->parse($yaml, $answers);
            $receipt = DecisionReceiptInput::load($decision, $projectRoot);
            $authorizer = ($this->authorizer)();
            if (!$authorizer instanceof ProjectConfigAuthorizer) {
                throw new \RuntimeException(
                    'Project configuration authorization requires configured CFG-04 signing custody on this authoring host.',
                );
            }

            $authorization = $authorizer->authorize($manifest, $receipt);
            if (\is_string($expectedManifestDigest) && \is_string($expectedPlanDigest)) {
                $authorization->assertMatches($expectedManifestDigest, $expectedPlanDigest);
            }
            $io->writeRaw($authorization->canonicalJson() . "\n");

            return 0;
        } catch (\Throwable $exception) {
            $io->error('project:config:authorize refused: ' . $exception->getMessage());

            return 1;
        }
    }

    private function resolvePath(string $path, string $projectRoot): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
            ? $path
            : rtrim($projectRoot, '/\\') . '/' . $path;
    }
}
