<?php

declare(strict_types=1);

namespace Waaseyaa\ErrorHandler;

final class EditorLinkGenerator
{
    public function __construct(private readonly string $editorId = 'vscode') {}

    public function link(string $absolutePath, int $line): string
    {
        return match ($this->editorId) {
            'phpstorm', 'phpstorm.sh' => sprintf('phpstorm://open?file=%s&line=%d', rawurlencode($absolutePath), $line),
            'subl', 'sublime' => sprintf('subl://open?url=file://%s&line=%d', rawurlencode($absolutePath), $line),
            default => sprintf('vscode://file%s:%d', $absolutePath, $line),
        };
    }
}
