<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\SiteContract\SiteManifestParser;

/** Reads the consumer's committed site contract through the canonical compiler. @api */
final readonly class ProjectConfigSiteIdentity
{
    public function __construct(private string $projectRoot) {}

    /** @return array{manifest: string, plan: string} */
    public function evaluate(): array
    {
        $path = rtrim($this->projectRoot, '/\\') . '/.waaseyaa/site.yaml';
        $bytes = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!\is_string($bytes)) {
            throw new \InvalidArgumentException('The consumer has no readable committed .waaseyaa/site.yaml contract.');
        }
        $manifest = new SiteManifestParser()->parse($bytes, $path);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        return ['manifest' => $manifest->digest, 'plan' => $plan->digest];
    }
}
