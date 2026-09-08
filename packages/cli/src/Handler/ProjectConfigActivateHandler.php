<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivation;
use Waaseyaa\CLI\ProjectInit\InitialProjectConfigActivationException;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\CLI\ProjectInit\ProjectConfigSiteIdentity;
use Waaseyaa\SiteContract\CanonicalJson;

/** Consumer-host command handler for the fresh project's signed initial configuration. @api */
final readonly class ProjectConfigActivateHandler
{
    public function __construct(
        private InitialProjectConfigActivation $activation,
        private ProjectConfigSiteIdentity $siteIdentity,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $path = trim((string) ($io->option('authorization') ?? ''));
            if ($path === '') {
                throw new \InvalidArgumentException('project:config:activate requires --authorization.');
            }
            $bytes = is_file($path) && !is_link($path)
                ? file_get_contents($path)
                : false;
            if (!\is_string($bytes)) {
                throw new \InvalidArgumentException(sprintf('Project configuration authorization is unreadable: %s', $path));
            }
            $authorization = ProjectConfigAuthorization::fromJson($bytes);
            $identity = $this->siteIdentity->evaluate();
            $result = $this->activation->activate($authorization, $identity['manifest'], $identity['plan']);
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
