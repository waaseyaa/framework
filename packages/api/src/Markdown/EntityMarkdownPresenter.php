<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Markdown;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\ViewModeConfigInterface;

/**
 * Renders an entity to clean Markdown for the same URL that serves HTML.
 *
 * Field resolution and — critically — access filtering are delegated to
 * {@see ResourceSerializer::serialize()}: the attribute map this presenter
 * renders has already had internal/credential fields dropped and per-account
 * field access applied, so Markdown output can never leak a field the JSON:API
 * representation would hide. This guarantee is enforced structurally, not by
 * convention: {@see self::present()} takes $accessHandler and $account as
 * REQUIRED, non-nullable parameters, so the per-account field filter always
 * runs — a caller cannot omit it and silently fall back to unfiltered output.
 * View-mode field selection, ordering, and per-field formatter settings come
 * from {@see ViewModeConfigInterface} — the same source the HTML renderer
 * uses — so the two representations stay in lockstep.
 *
 * Output is deterministic: the SSR `?raw` toggle returns exactly these bytes.
 *
 * Field rendering: entity references become Markdown links, images become
 * alt-texted Markdown images, everything else renders as text. This is direct
 * Markdown assembly — no Markdown<->HTML conversion happens here.
 *
 * @api
 */
final class EntityMarkdownPresenter
{
    public function __construct(
        private readonly ResourceSerializer $serializer,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ViewModeConfigInterface $viewModeConfig,
    ) {}

    /**
     * Render an entity as Markdown.
     *
     * @param EntityAccessHandler $accessHandler Required. Together with $account,
     *                                           applies the per-account field
     *                                           filter — the same one
     *                                           {@see ResourceSerializer} uses for
     *                                           JSON:API. There is no unfiltered
     *                                           code path.
     * @param AccountInterface    $account       Required. The viewing account the
     *                                           field filter is evaluated against.
     * @param ?string             $canonicalUrl  Public URL of the resource, when
     *                                           known by the caller (the SSR
     *                                           layer); recorded in front matter.
     */
    public function present(
        EntityInterface $entity,
        string $viewMode,
        EntityAccessHandler $accessHandler,
        AccountInterface $account,
        ?string $canonicalUrl = null,
    ): string {
        $mode = $viewMode !== '' ? $viewMode : 'full';

        // Access-filtered, cast, normalized attribute map (security-critical reuse).
        $safe = $this->serializer->serialize($entity, $accessHandler, $account)->attributes;

        $entityTypeId = $entity->getEntityTypeId();
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $entity->bundle());
        $keys = $entityType->getKeys();
        // Entity-key fields (id, uuid, label, bundle, langcode, …) are metadata:
        // surfaced in front matter / the H1, never as body sections.
        $hiddenKeyFields = array_flip(array_values($keys));

        $display = $this->resolveDisplay($entityTypeId, $mode, $safe);

        // Front matter: machine-readable header agents can parse cheaply.
        $front = [
            'type' => $entityTypeId,
        ];
        if ($entity->bundle() !== '' && $entity->bundle() !== $entityTypeId) {
            $front['bundle'] = $entity->bundle();
        }
        $id = $entity->id();
        if ($id !== null && $id !== '') {
            $front['id'] = (string) $id;
        }
        if ($entity->uuid() !== '') {
            $front['uuid'] = $entity->uuid();
        }
        $front['view_mode'] = $mode;
        if ($canonicalUrl !== null && $canonicalUrl !== '') {
            $front['url'] = $canonicalUrl;
        }

        $out = $this->renderFrontMatter($front);
        $out .= '# ' . $this->escapeInline($entity->label() !== '' ? $entity->label() : $entityTypeId) . "\n";

        foreach ($display as $fieldName => $item) {
            // Render only fields that survived access filtering (present in $safe).
            if (!\array_key_exists($fieldName, $safe)) {
                continue;
            }
            // Entity-key fields (label, bundle, langcode, …) are metadata, not body.
            if (isset($hiddenKeyFields[$fieldName])) {
                continue;
            }

            $raw = $safe[$fieldName];
            if ($this->isEmpty($raw)) {
                continue;
            }

            $type = isset($fieldDefinitions[$fieldName]) ? $fieldDefinitions[$fieldName]->getType() : 'string';
            $settings = \is_array($item['settings'] ?? null) ? $item['settings'] : [];
            $rendered = $this->renderField($type, $raw, $settings);
            if (trim($rendered) === '') {
                continue;
            }

            $out .= "\n## " . $this->escapeInline($this->fieldLabel($fieldName, $settings)) . "\n\n";
            $out .= $rendered . "\n";
        }

        return $out;
    }

    /**
     * Resolve the ordered display map for a view mode, UNIONED with every
     * access-safe stored field.
     *
     * A view-mode display config must not silently drop the entity's actual
     * stored content (the body-drop bug): configured fields keep their
     * weight/settings, and any access-safe stored field the display omits is
     * appended after them. The union never widens beyond `$safe` — which is
     * already internal-field- and access-filtered — so nothing restricted leaks.
     *
     * @param array<string, mixed> $safe
     * @return array<string, array{formatter?: string, settings?: array<string, mixed>, weight?: int}>
     */
    private function resolveDisplay(string $entityTypeId, string $mode, array $safe): array
    {
        $display = $this->viewModeConfig->getDisplay($entityTypeId, $mode);

        // Append any stored field not already in the display, after the highest
        // configured weight, so e.g. `body` is never dropped.
        $nextWeight = 0;
        foreach ($display as $item) {
            $nextWeight = max($nextWeight, ($item['weight'] ?? 0) + 1);
        }
        foreach (array_keys($safe) as $name) {
            if (!isset($display[$name])) {
                $display[$name] = ['settings' => [], 'weight' => $nextWeight++];
            }
        }

        uasort($display, static fn(array $a, array $b): int => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

        return $display;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderField(string $type, mixed $value, array $settings): string
    {
        // Multi-value fields render as a Markdown bullet list.
        if (\is_array($value) && array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                if ($this->isEmpty($item)) {
                    continue;
                }
                $items[] = '- ' . $this->renderScalar($type, $item, $settings);
            }

            return implode("\n", $items);
        }

        return $this->renderScalar($type, $value, $settings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderScalar(string $type, mixed $value, array $settings): string
    {
        return match ($type) {
            'entity_reference' => $this->renderReference($value, $settings),
            'image' => $this->renderImage($value, $settings),
            'boolean' => (bool) $value ? 'true' : 'false',
            default => $this->renderText($value),
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderReference(mixed $value, array $settings): string
    {
        $id = $this->stringify($value);
        if ($id === '') {
            return '';
        }

        $label = (isset($settings['label']) && \is_string($settings['label']) && $settings['label'] !== '')
            ? $settings['label']
            : $id;
        $pattern = (isset($settings['url_pattern']) && \is_string($settings['url_pattern']) && $settings['url_pattern'] !== '')
            ? $settings['url_pattern']
            : '/entity/{id}';
        $href = str_replace('{id}', rawurlencode($id), $pattern);

        return '[' . $this->escapeInline($label) . '](' . $this->escapeUrl($href) . ')';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderImage(mixed $value, array $settings): string
    {
        $src = $this->stringify($value);
        if ($src === '') {
            return '';
        }
        $alt = (isset($settings['alt']) && \is_string($settings['alt'])) ? $settings['alt'] : '';

        return '![' . $this->escapeInline($alt) . '](' . $this->escapeUrl($src) . ')';
    }

    private function renderText(mixed $value): string
    {
        if (\is_array($value)) {
            return '```json' . "\n" . (json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)) . "\n" . '```';
        }

        return trim($this->stringify($value));
    }

    /**
     * @param array<string, scalar|null> $front
     */
    private function renderFrontMatter(array $front): string
    {
        $lines = ['---'];
        foreach ($front as $key => $value) {
            $lines[] = $key . ': ' . $this->frontMatterValue((string) $value);
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n\n";
    }

    private function frontMatterValue(string $value): string
    {
        // Quote values that could be misread as YAML structure.
        if ($value === '' || preg_match('/[:#\[\]{}",\n]/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function stringify(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function fieldLabel(string $fieldName, array $settings): string
    {
        if (isset($settings['label']) && \is_string($settings['label']) && $settings['label'] !== '') {
            return $settings['label'];
        }

        return ucwords(str_replace('_', ' ', $fieldName));
    }

    /**
     * Escape characters that would break inline Markdown / front matter text.
     */
    private function escapeInline(string $text): string
    {
        // Neutralize the structural characters that matter inline; keep it light
        // so prose stays readable to an agent.
        return str_replace(
            ['\\', '[', ']', '`'],
            ['\\\\', '\\[', '\\]', '\\`'],
            $text,
        );
    }

    private function escapeUrl(string $url): string
    {
        // Parentheses break Markdown link syntax; percent-encode them.
        return str_replace(['(', ')', ' '], ['%28', '%29', '%20'], $url);
    }
}
