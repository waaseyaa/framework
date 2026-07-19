<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\CompiledPolicySubjectView;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityBase;
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
     * SCOPE: this filters only the `fields` bag. The entity LABEL/TITLE is NOT
     * filtered here — it bypasses this bag entirely and is resolved separately
     * by {@see \Waaseyaa\SSR\SsrPageHandler::handleRenderPage()} via
     * {@see \Waaseyaa\Access\EntityAccessHandler::viewableLabel()} before being
     * threaded into the Twig `title` context var and the schema.org JSON-LD
     * (R7 WP1) — so a policy that forbids the label-key field on 'view' is
     * honored for the title/JSON-LD `name` exactly as it is for the fields bag,
     * just via a different seam.
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

        // Resolve the projection from non-value-bearing field names first.
        // A denied field must never be read merely so it can be removed from
        // the finished Twig bag afterward.
        $fieldNames = array_values(array_filter(
            EntityValues::ordinaryFieldNames($entity),
            static fn(string $fieldName): bool => !in_array($fieldName, self::ALWAYS_INTERNAL_FIELDS, true)
                && !(isset($fieldDefinitions[$fieldName]) && $fieldDefinitions[$fieldName]->getSetting('internal') === true),
        ));

        if ($account !== null) {
            $fieldNames = $this->accessHandler !== null
                ? $this->accessHandler->filterFields($entity, $fieldNames, 'view', $account)
                : [];

            // Protected fields need an affirmative V2 release decision before
            // value projection. A legacy account object has no immutable
            // principal, so those names are withheld rather than probed.
            $fieldNames = array_values(array_filter(
                $fieldNames,
                function (string $fieldName) use ($entity, $account): bool {
                    if (!$entity instanceof EntityBase || $entity->fieldReadLevel($fieldName) === \Waaseyaa\Entity\FieldReadLevel::Public) {
                        return true;
                    }
                    if (!$account instanceof AuthorizationPrincipalInterface || $this->accessHandler === null) {
                        return false;
                    }

                    return $this->accessHandler->checkProtectedFieldRead(
                        $account,
                        $entity->entityStructure(),
                        new CompiledPolicySubjectView([]),
                        $fieldName,
                    )->isAllowed();
                },
            ));
        }

        $values = EntityValues::toCastAwareMap($entity, $fieldNames);

        if ($display === []) {
            $display = $this->buildDefaultDisplay($fieldDefinitions, $values, $definition->getKeys());
        } else {
            $display = array_intersect_key($display, array_flip($fieldNames));
        }

        uasort($display, static function (array $a, array $b): int {
            $wa = (int) ($a['weight'] ?? 0);
            $wb = (int) ($b['weight'] ?? 0);
            return $wa <=> $wb;
        });

        $fields = [];
        foreach ($display as $fieldName => $item) {
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
