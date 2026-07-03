<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\ViewModeConfigInterface;

final class EntityRenderer
{
    /**
     * Field names that are NEVER rendered, regardless of whether the entity
     * declares them as `#[Field(... internal: true)]`. Defense in depth for
     * entities that store credential material in raw `_data` keys.
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

    /**
     * $accessHandler is optional so existing call sites that render without a
     * request context (fixtures, previews with no account) keep working
     * unchanged. When {@see render()} is called WITH an $account (the real
     * SSR HTML request path — see {@see SsrPageHandler}), field-level access
     * control is enforced and fails closed if no handler is wired (see
     * {@see render()}).
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly FieldFormatterRegistry $formatterRegistry,
        private readonly ViewModeConfigInterface $viewModeConfig,
        private readonly ?EntityAccessHandler $accessHandler = null,
    ) {}

    /**
     * Build Twig variable bag for an entity + view mode.
     *
     * When $account is provided (the real SSR HTML request path), field-level
     * access control is enforced on the FIELDS BAG, on top of the
     * always-internal/`internal`-setting exclusions below: fields the viewing
     * account is forbidden from ('view') per
     * {@see EntityAccessHandler::filterFields()} are dropped from the bag
     * entirely — matching {@see \Waaseyaa\Api\ResourceSerializer::serialize()}
     * and {@see SsrPageHandler::renderEntityMarkdown()} for the field content
     * these templates print. This fails CLOSED: if $account is given but no
     * $accessHandler is wired, every field is dropped rather than rendered
     * unfiltered (defense in depth — production always wires accessHandler;
     * see {@see SsrPageHandler::renderEntityHtml()} for the primary fail-closed
     * guard that refuses to render at all in that case).
     *
     * SCOPE CAVEAT: this filters only the `fields` bag. The entity LABEL/TITLE
     * is NOT filtered here — it is read directly from raw storage by the
     * template's `<title>` block ({@see \Waaseyaa\Entity\EntityInterface::label()})
     * and by the schema.org JSON-LD ({@see \Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper}),
     * bypassing this bag. A policy that forbids the label-key field on 'view'
     * would still expose the title (identical to the Markdown H1's existing
     * behavior; JSON:API's ResourceSerializer, by contrast, DOES filter the
     * label). Closing that cross-package label channel is tracked as a
     * follow-up (R7); it is not part of this change.
     *
     * @return array{
     *   entity: EntityInterface,
     *   entity_type: string,
     *   bundle: string,
     *   view_mode: string,
     *   template_suggestions: list<string>,
     *   fields: array<string, array{raw: mixed, formatted: string, type: string}>
     * }
     */
    public function render(EntityInterface $entity, ViewMode|string $viewMode = 'full', ?AccountInterface $account = null): array
    {
        $mode = $viewMode instanceof ViewMode ? $viewMode->name : (string) $viewMode;
        if ($mode === '') {
            $mode = 'full';
        }

        $entityTypeId = $entity->getEntityTypeId();
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $fieldDefinitions = $definition->getFieldDefinitions();
        $display = $this->viewModeConfig->getDisplay($entityTypeId, $mode);
        $values = EntityValues::toCastAwareMap($entity);

        if ($display === []) {
            $display = $this->buildDefaultDisplay($fieldDefinitions, $values, $definition->getKeys());
        }

        uasort($display, static function (array $a, array $b): int {
            $wa = (int) ($a['weight'] ?? 0);
            $wb = (int) ($b['weight'] ?? 0);
            return $wa <=> $wb;
        });

        $fields = [];
        foreach ($display as $fieldName => $item) {
            if (in_array($fieldName, self::ALWAYS_INTERNAL_FIELDS, true)
                || (isset($fieldDefinitions[$fieldName]) && $fieldDefinitions[$fieldName]->getSetting('internal') === true)) {
                continue;
            }

            $raw = $values[$fieldName] ?? null;
            $fieldType = isset($fieldDefinitions[$fieldName]) ? $fieldDefinitions[$fieldName]->getType() : 'string';
            $formatterType = (string) ($item['formatter'] ?? $fieldType);
            $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];

            $fields[$fieldName] = [
                'raw' => $raw,
                'formatted' => $this->formatterRegistry->format($formatterType, $raw, $settings),
                'type' => $fieldType,
            ];
        }

        if ($account !== null) {
            if ($this->accessHandler !== null) {
                $allowedFieldNames = $this->accessHandler->filterFields($entity, array_keys($fields), 'view', $account);
                $fields = array_intersect_key($fields, array_flip($allowedFieldNames));
            } else {
                // Fail closed (defense in depth): enforcement was requested
                // (an $account was supplied) but no access handler is
                // available to evaluate it. Deny all fields rather than risk
                // a leak. Production never reaches this branch —
                // SsrPageHandler::renderEntityHtml() refuses to render at all
                // (500) before constructing an entity bag when its
                // accessHandler is null.
                $fields = [];
            }
        }

        return [
            'entity' => $entity,
            'entity_type' => $entityTypeId,
            'bundle' => $entity->bundle(),
            'view_mode' => $mode,
            'template_suggestions' => $this->buildTemplateSuggestions($entityTypeId, (string) $entity->bundle(), $mode),
            'fields' => $fields,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildTemplateSuggestions(string $entityTypeId, string $bundle, string $mode): array
    {
        $modeSafe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($mode)) ?: 'full';
        $bundleSafe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($bundle)) ?: $entityTypeId;

        $suggestions = [
            sprintf('%s.%s.%s.html.twig', $entityTypeId, $bundleSafe, $modeSafe),
        ];

        if ($modeSafe !== 'full') {
            $suggestions[] = sprintf('%s.%s.full.html.twig', $entityTypeId, $bundleSafe);
        }

        $suggestions[] = sprintf('%s.%s.html.twig', $entityTypeId, $modeSafe);
        if ($modeSafe !== 'full') {
            $suggestions[] = sprintf('%s.full.html.twig', $entityTypeId);
        }
        $suggestions[] = 'entity.html.twig';

        return array_values(array_unique($suggestions));
    }

    /**
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @return array<string, array{formatter: string, settings: array<string, mixed>, weight: int}>
     */
    private function buildDefaultDisplay(array $fieldDefinitions, array $values, array $entityKeys): array
    {
        $hidden = array_values($entityKeys);
        $display = [];
        $weight = 0;

        foreach ($values as $name => $value) {
            if (in_array($name, $hidden, true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $display[$name] = [
                'formatter' => isset($fieldDefinitions[$name]) ? $fieldDefinitions[$name]->getType() : 'string',
                'settings' => [],
                'weight' => $weight++,
            ];
        }

        return $display;
    }
}
