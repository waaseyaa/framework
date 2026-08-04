<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Spec;

use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;

final class SpecIndexProvider implements GraphSectionProviderInterface
{
    /** @param array<string> $additionalPaths */
    public function __construct(
        private readonly ?string $specsDirectory,
        private readonly array $additionalPaths = [],
    ) {}

    public function getKey(): string
    {
        return 'spec_index';
    }

    public function provide(): GraphSection
    {
        $data = [];

        $directories = [
            ...($this->specsDirectory !== null ? [$this->specsDirectory] : []),
            ...$this->additionalPaths,
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*.md');

            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $name = basename($file, '.md');
                $data[$name] = [
                    'name' => $name,
                    'path' => $file,
                ];
            }
        }

        return new GraphSection(
            key: 'spec_index',
            version: '1.0.0',
            data: $data,
        );
    }
}
