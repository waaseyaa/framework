<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/**
 * Optional UI customization for the admin SPA (header links, sidebar items).
 *
 * Hosts build this via {@see GenericAdminSurfaceHost::buildAdminUi()} and attach it to
 * {@see AdminSurfaceSessionData}. Invalid entries are dropped during normalization.
 * @api
 */
final readonly class AdminSurfaceUiPayload
{
    /**
     * @param list<array{label: string, href: string, external?: bool}>       $headerLinks
     * @param list<array{id: string, label: string, href: string, group?: string, weight?: int}> $sidebarItems
     */
    private function __construct(
        public array $headerLinks,
        public array $sidebarItems,
        public string $navigationMode,
    ) {}

    /**
     * @param list<array<string, mixed>> $headerLinks
     * @param list<array<string, mixed>> $sidebarItems
     */
    public static function fromArrays(array $headerLinks = [], array $sidebarItems = [], string $navigationMode = 'full'): self
    {
        return new self(
            self::normalizeHeaderLinks($headerLinks),
            self::normalizeSidebarItems($sidebarItems),
            in_array($navigationMode, ['full', 'catalog-only'], true) ? $navigationMode : 'full',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->headerLinks !== []) {
            $out['headerLinks'] = $this->headerLinks;
        }
        if ($this->sidebarItems !== []) {
            $out['sidebarItems'] = $this->sidebarItems;
        }
        if ($this->navigationMode !== 'full') {
            $out['navigationMode'] = $this->navigationMode;
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->headerLinks === [] && $this->sidebarItems === [] && $this->navigationMode === 'full';
    }

    /**
     * @return list<array{label: string, href: string, external?: bool}>
     */
    private static function normalizeHeaderLinks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            $href = isset($row['href']) ? trim((string) $row['href']) : '';
            if ($label === '' || $href === '') {
                continue;
            }
            $item = ['label' => $label, 'href' => $href];
            if (array_key_exists('external', $row) && is_bool($row['external'])) {
                $item['external'] = $row['external'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, href: string, group?: string, weight?: int}>
     */
    private static function normalizeSidebarItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            $href = isset($row['href']) ? trim((string) $row['href']) : '';
            if ($id === '' || $label === '' || $href === '') {
                continue;
            }
            $item = [
                'id' => $id,
                'label' => $label,
                'href' => $href,
            ];
            if (isset($row['group']) && is_string($row['group']) && $row['group'] !== '') {
                $item['group'] = $row['group'];
            }
            if (isset($row['weight']) && is_numeric($row['weight'])) {
                $item['weight'] = (int) $row['weight'];
            }
            $out[] = $item;
        }

        return $out;
    }
}
