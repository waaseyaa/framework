<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Scaffold;

use Waaseyaa\Field\FieldScaffoldProjection;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

/**
 * The pure content-type scaffold compiler (#2789 phase 2, ADR-025 D-6.1).
 *
 * It is a function of its validated input plus its own version: no filesystem
 * observation, no project reference, no clock. Two runs on the same input
 * produce byte-identical plans, which is what makes the plan reviewable and
 * what lets `make:content-type` hand publication to the shared execution
 * authority instead of writing files and rewriting `composer.json` itself.
 *
 * The unit is **seeded** (D-2.2): a scaffold is published exactly once and is
 * then the developer's to edit, so the authority never re-renders it. Its set
 * evolution stays `Frozen` — a scaffold that later wanted to add a path would
 * be asking to own bytes its developer already owns. The provider registration
 * travels as a plan-borne merge instruction (D-6.6), so it lands inside the
 * same transaction as the two files and preserves the application's own
 * `composer.json` bytes.
 *
 * @api
 */
final readonly class ContentTypeScaffoldCompiler
{
    public const int GENERATOR_VERSION = 1;

    /** Every scaffolded content type owns one unit under this namespace. */
    public const string UNIT_PREFIX = 'scaffold:content-type';

    /** The D-2.1 unit-id grammar one colon-separated segment must satisfy. */
    private const string UNIT_SEGMENT_GRAMMAR = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public function __construct(private FieldScaffoldProjection $fieldProjection) {}

    /**
     * @param string $name the validated content-type name as the operator typed it
     * @param string $className the validated PascalCase class base derived from $name
     * @param list<array{name: string, type: string, target: ?string}> $fields validated, non-empty
     */
    public function compile(string $name, string $className, array $fields): ArtifactPlan
    {
        if ($name === '' || $className === '' || $fields === []) {
            throw new \InvalidArgumentException('A content-type scaffold requires a name, a class name and at least one field.');
        }
        $typeId = strtolower($name);
        $label = ucwords(strtr($name, '_', ' '));
        $labelField = $this->labelField($fields);
        $providerClass = $className . 'ServiceProvider';

        return new ArtifactPlan(
            self::class,
            self::GENERATOR_VERSION,
            self::unitId($typeId),
            GenerationUnitDisposition::Seeded,
            self::inputDigest($name, $fields),
            [
                new GeneratedArtifact(
                    'src/Entity/' . $className . '.php',
                    $this->renderEntity($className, $typeId, $label, $labelField, $fields),
                ),
                new GeneratedArtifact(
                    'src/Provider/' . $providerClass . '.php',
                    $this->renderProvider($providerClass, $className),
                ),
            ],
            registrations: [new ComposerProviderRegistration('App\\Provider\\' . $providerClass)],
        );
    }

    /**
     * The label key remains the first authored string field, else the first
     * field. PHP property projection does not change entity label semantics.
     *
     * @param list<array{name: string, type: string, target: ?string}> $fields
     */
    public function labelField(array $fields): string
    {
        foreach ($fields as $field) {
            if ($field['type'] === 'string') {
                return $field['name'];
            }
        }

        return $fields[0]['name'];
    }

    /**
     * A unit id is an ownership key, not display text, and D-2.1's grammar is
     * ASCII. A content-type name may be Indigenous orthography — syllabics or
     * diacritics the charter forbids transliterating — so a name the grammar
     * cannot spell is addressed by a stable digest of itself instead. The
     * orthography stays verbatim where it is read: the class name, the label,
     * and the generated file paths.
     */
    public static function unitId(string $typeId): string
    {
        $slug = strtr($typeId, '_', '-');

        return preg_match(self::UNIT_SEGMENT_GRAMMAR, $slug) === 1
            ? self::UNIT_PREFIX . ':' . $slug
            : self::UNIT_PREFIX . ':x' . substr(hash('sha256', $typeId), 0, 32);
    }

    /** @param list<array{name: string, type: string, target: ?string}> $fields */
    private static function inputDigest(string $name, array $fields): string
    {
        return hash('sha256', CanonicalJson::encode(['name' => $name, 'fields' => $fields]) . "\n");
    }

    /**
     * @param list<array{name: string, type: string, target: ?string}> $fields
     */
    private function renderEntity(string $className, string $typeId, string $label, string $labelField, array $fields): string
    {
        $lines = [];
        // Published flag first — make published content public-read by default.
        $lines[] = "    #[Field(type: 'boolean', label: 'Published', default: true)]";
        $lines[] = '    public bool $status = true;';
        $lines[] = '';

        foreach ($fields as $field) {
            $definition = $this->fieldProjection->definition($field['name'], $field['type'], $field['target']);
            ['phpType' => $phpType, 'defaultLiteral' => $default] = $this->fieldProjection->property($definition);
            // The handler has already identifier-validated the name/target and
            // registry-validated the type. Escape them again before they land
            // in single-quoted attribute literals — escape-at-the-sink,
            // independent of upstream validation.
            $fieldLabel = addslashes(ucwords(strtr($field['name'], '_', ' ')));
            $typeLiteral = var_export($field['type'], true);
            $attrArgs = "type: {$typeLiteral}, label: '{$fieldLabel}'";
            if ($this->fieldProjection->valueKind($field['type']) === FieldValueKind::EntityReference) {
                $safeTarget = addslashes((string) $field['target']);
                $attrArgs .= ", settings: ['target_entity_type_id' => '{$safeTarget}']";
            }
            $lines[] = "    #[Field({$attrArgs})]";
            $lines[] = "    public {$phpType} \${$field['name']} = {$default};";
            $lines[] = '';
        }
        $fieldBlock = rtrim(implode("\n", $lines));
        $safeLabel = addslashes($label);
        $safeLabelField = addslashes($labelField);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            use Waaseyaa\\Entity\\Attribute\\ContentEntityKeys;
            use Waaseyaa\\Entity\\Attribute\\ContentEntityType;
            use Waaseyaa\\Entity\\Attribute\\Field;
            use Waaseyaa\\Entity\\ContentEntityBase;

            #[ContentEntityType(id: '{$typeId}', label: '{$safeLabel}')]
            #[ContentEntityKeys(label: '{$safeLabelField}')]
            final class {$className} extends ContentEntityBase
            {
            {$fieldBlock}
            }

            PHP;
    }

    private function renderProvider(string $providerClass, string $className): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Provider;

            use App\\Entity\\{$className};
            use Waaseyaa\\Entity\\EntityType;
            use Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider;

            final class {$providerClass} extends ServiceProvider
            {
                public function register(): void
                {
                    \$this->entityType(EntityType::fromClass({$className}::class, group: 'content'));
                }
            }

            PHP;
    }
}
