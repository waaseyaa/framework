<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

final class InertiaResponse
{
    /**
     * @param array<string, mixed> $props
     * @param array<string, list<string>> $deferredProps
     * @param list<string> $mergeProps
     * @param list<string> $prependProps
     * @param list<string> $deepMergeProps
     * @param array<string, mixed> $onceProps
     */
    public function __construct(
        public readonly string $component,
        public readonly array $props,
        public readonly string $url,
        public readonly string $version,
        public readonly bool $encryptHistory = false,
        public readonly bool $clearHistory = false,
        public readonly bool $preserveFragment = false,
        public readonly array $deferredProps = [],
        public readonly array $mergeProps = [],
        public readonly array $prependProps = [],
        public readonly array $deepMergeProps = [],
        public readonly array $onceProps = [],
    ) {}

    /** @return array<string, mixed> */
    public function toPageObject(): array
    {
        $props = $this->props;
        $props['errors'] ??= [];

        $page = [
            'component' => $this->component,
            'props' => $props,
            'url' => $this->url,
            'version' => $this->version,
        ];

        if ($this->encryptHistory) {
            $page['encryptHistory'] = true;
        }
        if ($this->clearHistory) {
            $page['clearHistory'] = true;
        }
        if ($this->preserveFragment) {
            $page['preserveFragment'] = true;
        }
        if ($this->deferredProps !== []) {
            $page['deferredProps'] = $this->deferredProps;
        }
        if ($this->mergeProps !== []) {
            $page['mergeProps'] = $this->mergeProps;
        }
        if ($this->prependProps !== []) {
            $page['prependProps'] = $this->prependProps;
        }
        if ($this->deepMergeProps !== []) {
            $page['deepMergeProps'] = $this->deepMergeProps;
        }
        if ($this->onceProps !== []) {
            $page['onceProps'] = $this->onceProps;
        }

        return $page;
    }
}
