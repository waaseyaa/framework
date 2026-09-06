<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Scaffold;

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

    /** Field type => [phpType, default literal, explicitType?]. */
    public const array TYPE_MAP = [
        'string' => ['string', "''", false],
        'text' => ['?string', 'null', true],
        'integer' => ['?int', 'null', true],
        'float' => ['?float', 'null', true],
        'boolean' => ['bool', 'false', true],
        'datetime' => ['?int', 'null', true],
        'entity_reference' => ['?int', 'null', true],
    ];

    /** The D-2.1 unit-id grammar one colon-separated segment must satisfy. */
    private const string UNIT_SEGMENT_GRAMMAR = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

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
        $labelField = self::labelField($fields);
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
     * The label key is the first string field, else the first field — the rule
     * the generated `#[ContentEntityKeys]` attribute has always used.
     *
     * @param list<array{name: string, type: string, target: ?string}> $fields
     */
    public static function labelField(array $fields): string
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
            [$phpType, $default] = self::TYPE_MAP[$field['type']];
            // $field['name']/['type']/['target'] are already allowlist-validated
            // by the handler; $fieldLabel is derived from an already-validated
            // name. Escape all of them anyway before they land in single-quoted
            // attribute literals — escape-at-the-sink, independent of upstream
            // validation (matches the ExtensionScaffoldHandler pattern).
            $fieldLabel = addslashes(ucwords(strtr($field['name'], '_', ' ')));
            $attrArgs = "type: '{$field['type']}', label: '{$fieldLabel}'";
            if ($field['type'] === 'entity_reference') {
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
