<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\Attribute\BundleTemplate;
use Waaseyaa\Field\Attribute\FieldTemplate;

/**
 * Scans classes annotated with #[BundleTemplate], reads their #[FieldTemplate]
 * attributes, and registers the resulting FieldDefinition objects with
 * FieldDefinitionRegistry::registerBundleFields().
 *
 * Discovery strategy: the compiler accepts an explicit list of class names via
 * compile(). The caller (FieldServiceProvider::boot()) controls which classes
 * are scanned. Passing an empty list is valid and makes compile() a no-op.
 * WP10 may wire automatic discovery from PackageManifest; for now callers
 * explicitly supply the list of template classes they own.
 */
final class BundleTemplateCompiler
{
    private bool $compiled = false;

    public function __construct(
        private readonly FieldDefinitionRegistryInterface $registry,
    ) {}

    /**
     * Compile all classes in the supplied list that are annotated with
     * #[BundleTemplate]. Properties are processed before methods, both in
     * declaration order. Compilation is idempotent — subsequent calls return
     * early without re-registering.
     *
     * @param list<string> $classes Fully-qualified class names to scan.
     *
     * @throws \InvalidArgumentException On duplicate field key or normalized prompt alias within a bundle.
     */
    public function compile(array $classes = []): void
    {
        if ($this->compiled) {
            return;
        }

        $this->compiled = true;

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $ref = new \ReflectionClass($className);

            $bundleAttrs = $ref->getAttributes(BundleTemplate::class);
            if ($bundleAttrs === []) {
                continue;
            }

            /** @var BundleTemplate $bundleTpl */
            $bundleTpl = $bundleAttrs[0]->newInstance();

            $fields = $this->collectFields($ref, $bundleTpl);

            $this->registry->registerBundleFields($bundleTpl->entityType, $bundleTpl->bundle, $fields);
        }
    }

    /**
     * Collect FieldDefinition objects from a template class.
     * Properties are enumerated in declaration order, then methods.
     *
     * @return list<FieldDefinition>
     */
    private function collectFields(\ReflectionClass $ref, BundleTemplate $bundleTpl): array
    {
        $fields = [];
        $seenKeys = [];
        $seenAliases = [];

        // Properties first, in declaration order.
        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(FieldTemplate::class) as $attr) {
                /** @var FieldTemplate $tpl */
                $tpl = $attr->newInstance();
                $this->addField($tpl, $bundleTpl, $fields, $seenKeys, $seenAliases);
            }
        }

        // Methods second, in declaration order.
        foreach ($ref->getMethods() as $method) {
            foreach ($method->getAttributes(FieldTemplate::class) as $attr) {
                /** @var FieldTemplate $tpl */
                $tpl = $attr->newInstance();
                $this->addField($tpl, $bundleTpl, $fields, $seenKeys, $seenAliases);
            }
        }

        return $fields;
    }

    /**
     * Build one FieldDefinition from a FieldTemplate, validate uniqueness, and append it.
     *
     * @param list<FieldDefinition> $fields
     * @param array<string, true> $seenKeys
     * @param array<string, true> $seenAliases
     */
    private function addField(
        FieldTemplate $tpl,
        BundleTemplate $bundleTpl,
        array &$fields,
        array &$seenKeys,
        array &$seenAliases,
    ): void {
        $bundleContext = "{$bundleTpl->entityType}:{$bundleTpl->bundle}";

        if (isset($seenKeys[$tpl->key])) {
            throw new \InvalidArgumentException(
                "Duplicate field key '{$tpl->key}' in bundle '{$bundleContext}'",
            );
        }
        $seenKeys[$tpl->key] = true;

        foreach ($tpl->promptAliases as $alias) {
            $normalized = $this->normalize($alias);
            if ($normalized === '') {
                continue;
            }
            if (isset($seenAliases[$normalized])) {
                throw new \InvalidArgumentException(
                    "Duplicate prompt alias '{$alias}' (normalized: '{$normalized}') in bundle '{$bundleContext}'",
                );
            }
            $seenAliases[$normalized] = true;
        }

        $fields[] = new FieldDefinition(
            name: $tpl->key,
            type: $tpl->type,
            label: $tpl->label,
            required: $tpl->required,
            readOnly: $tpl->readOnly,
            targetEntityTypeId: $bundleTpl->entityType,
            targetBundle: $bundleTpl->bundle,
            group: $tpl->group,
            promptAliases: $tpl->promptAliases,
            read: $tpl->read,
        );
    }

    /**
     * Normalize a prompt alias for uniqueness comparison.
     * Lowercases (UTF-8), collapses internal whitespace, trims.
     */
    private function normalize(string $s): string
    {
        $lower = mb_strtolower($s, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $lower);

        return trim($collapsed ?? '');
    }
}
