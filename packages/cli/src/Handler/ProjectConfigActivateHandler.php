<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivation;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivationException;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\SiteContract\CanonicalJson;

/** Consumer-host command handler for the fresh project's signed initial configuration. @api */
final readonly class ProjectConfigActivateHandler
{
    public function __construct(private InitialProjectConfigActivation $activation) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $path = trim((string) ($io->option('authorization') ?? ''));
            $manifestDigest = trim((string) ($io->option('site-manifest-digest') ?? ''));
            $planDigest = trim((string) ($io->option('site-plan-digest') ?? ''));
            if ($path === '' || $manifestDigest === '' || $planDigest === '') {
                throw new \InvalidArgumentException(
                    'project:config:activate requires --authorization, --site-manifest-digest, and --site-plan-digest.',
                );
            }
            $bytes = is_file($path) && !is_link($path)
                ? file_get_contents($path)
                : false;
            if (!\is_string($bytes)) {
                throw new \InvalidArgumentException(sprintf('Project configuration authorization is unreadable: %s', $path));
            }
            $authorization = ProjectConfigAuthorization::fromJson($bytes);
            $result = $this->activation->activate($authorization, $manifestDigest, $planDigest);
            $io->writeRaw(CanonicalJson::encode([
                'schema' => 'waaseyaa.project_config_activation_result',
                'version' => 1,
                ...$result->toArray(),
                'errors' => [],
            ]) . "\n");

            return 0;
        } catch (InitialProjectConfigActivationException $exception) {
            $io->writeRaw($this->failure($exception->outcomeUncertain ? 'uncertain' : 'refused', $exception));

            return 1;
        } catch (\Throwable $exception) {
            $io->writeRaw($this->failure('refused', $exception));

            return 1;
        }
    }

    private function failure(string $status, \Throwable $exception): string
    {
        return CanonicalJson::encode([
            'schema' => 'waaseyaa.project_config_activation_result',
            'version' => 1,
            'status' => $status,
            'errors' => [['message' => $exception->getMessage()]],
        ]) . "\n";
    }
}
