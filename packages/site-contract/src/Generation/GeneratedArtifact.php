<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/** @api */
final readonly class GeneratedArtifact
{
    public function __construct(
        public string $path,
        public string $content,
        public int $mode = 0o644,
        public ?string $extensionRegion = null,
    ) {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains("/{$path}/", '/../')) {
            throw new \InvalidArgumentException('Generated artifact paths must be safe project-relative paths.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('Generated artifact content must not be empty.');
        }
        if (!in_array($mode, [0o644, 0o755], true)) {
            throw new \InvalidArgumentException('Generated artifact mode must be 0644 or 0755.');
        }
        if (str_ends_with($path, '.php') || $path === 'bin/maintenance/site-verify') {
            try {
                \PhpToken::tokenize($content, TOKEN_PARSE);
            } catch (\ParseError $exception) {
                throw new \InvalidArgumentException("Generated PHP artifact has invalid syntax: {$path}", previous: $exception);
            }
        }
        if ($extensionRegion !== null) {
            $this->regionBounds($content);
        }
    }

    public function managedDigest(?string $content = null): string
    {
        return hash('sha256', $this->managedContent($content ?? $this->content));
    }

    public function withExtensionFrom(string $existing): self
    {
        if ($this->extensionRegion === null) {
            return $this;
        }
        [$existingStart, $existingEnd] = $this->regionBounds($existing);
        [$generatedStart, $generatedEnd] = $this->regionBounds($this->content);
        $extension = substr($existing, $existingStart, $existingEnd - $existingStart);
        $content = substr($this->content, 0, $generatedStart) . $extension . substr($this->content, $generatedEnd);

        return new self($this->path, $content, $this->mode, $this->extensionRegion);
    }

    private function managedContent(string $content): string
    {
        if ($this->extensionRegion === null) {
            return $content;
        }
        [$start, $end] = $this->regionBounds($content);

        return substr($content, 0, $start) . substr($content, $end);
    }

    /** @return array{int, int} */
    private function regionBounds(string $content): array
    {
        $startMarker = '<!-- waaseyaa:extension:start ' . $this->extensionRegion . ' -->';
        $endMarker = '<!-- waaseyaa:extension:end ' . $this->extensionRegion . ' -->';
        $startMarkerOffset = strpos($content, $startMarker);
        $endMarkerOffset = strpos($content, $endMarker);
        if ($startMarkerOffset === false || $endMarkerOffset === false || $endMarkerOffset <= $startMarkerOffset) {
            throw new \InvalidArgumentException("Generated artifact {$this->path} has an invalid extension region.");
        }
        if (strpos($content, $startMarker, $startMarkerOffset + 1) !== false || strpos($content, $endMarker, $endMarkerOffset + 1) !== false) {
            throw new \InvalidArgumentException("Generated artifact {$this->path} has duplicate extension markers.");
        }
        $start = $startMarkerOffset + strlen($startMarker);

        return [$start, $endMarkerOffset];
    }
}
