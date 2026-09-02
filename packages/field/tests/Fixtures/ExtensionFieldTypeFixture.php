<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Fixtures;

/**
 * Declares runtime-generated `#[FieldType]` plugins for extension-path tests.
 *
 * The classes are written to a temp file and `require`d, never committed under
 * a PSR-4 root, so Composer's classmap (optimized or not) can never carry them
 * and the monorepo's own package manifest can never discover them by accident.
 * Every declaration gets a unique class name so tests may declare the same
 * field-type id twice (the collision cases) without redeclaring a class.
 */
final class ExtensionFieldTypeFixture
{
    public const NAMESPACE = 'Waaseyaa\\Field\\Tests\\Fixtures\\Generated';

    /** @var array<string, mixed> */
    public const VALUE_JSON_SCHEMA = ['type' => 'string', 'maxLength' => 64];

    /** @var array<string, mixed> */
    public const COLUMN_SCHEMA = ['type' => 'varchar', 'length' => 64];

    /**
     * A complete plugin: carries `#[FieldType]` and extends `AbstractFieldType`.
     *
     * @return class-string<\Waaseyaa\Field\FieldTypeInterface>
     */
    public static function declare(string $id, string $label = 'Fixture field type'): string
    {
        $class = self::uniqueClassName($id);

        return self::write($class, sprintf(
            <<<'PHP'
            #[\Waaseyaa\Field\Attribute\FieldType(id: %s, label: %s)]
            final class %s extends \Waaseyaa\Field\AbstractFieldType
            {
                public static function schema(): array
                {
                    return ['value' => ['type' => 'varchar', 'length' => 64]];
                }

                public static function jsonSchema(): array
                {
                    return ['type' => 'string', 'maxLength' => 64];
                }
            }
            PHP,
            var_export($id, true),
            var_export($label, true),
            self::shortName($class),
        ));
    }

    /**
     * Extends `AbstractFieldType` but carries no `#[FieldType]` attribute, so it
     * has no id to be admitted under.
     *
     * @return class-string
     */
    public static function declareWithoutAttribute(): string
    {
        $class = self::uniqueClassName('no_attribute');

        return self::write($class, sprintf(
            <<<'PHP'
            final class %s extends \Waaseyaa\Field\AbstractFieldType
            {
                public static function schema(): array
                {
                    return ['value' => ['type' => 'varchar', 'length' => 64]];
                }

                public static function jsonSchema(): array
                {
                    return ['type' => 'string', 'maxLength' => 64];
                }
            }
            PHP,
            self::shortName($class),
        ));
    }

    /**
     * Carries `#[FieldType]` but is not a `FieldTypeInterface` implementation, so
     * the registry has no static seam to project schemas through.
     *
     * @return class-string
     */
    public static function declareNonFieldType(string $id): string
    {
        $class = self::uniqueClassName($id . '_plain');

        return self::write($class, sprintf(
            <<<'PHP'
            #[\Waaseyaa\Field\Attribute\FieldType(id: %s, label: 'Not a field type')]
            final class %s
            {
            }
            PHP,
            var_export($id, true),
            self::shortName($class),
        ));
    }

    /**
     * Carries the deprecated Foundation `#[AsFieldType]` marker only.
     *
     * @return class-string
     */
    public static function declareLegacyAsFieldType(string $id): string
    {
        $class = self::uniqueClassName($id . '_legacy');

        return self::write($class, sprintf(
            <<<'PHP'
            #[\Waaseyaa\Foundation\Attribute\AsFieldType(id: %s, label: 'Legacy marker')]
            final class %s extends \Waaseyaa\Field\AbstractFieldType
            {
                public static function schema(): array
                {
                    return ['value' => ['type' => 'varchar', 'length' => 64]];
                }

                public static function jsonSchema(): array
                {
                    return ['type' => 'string', 'maxLength' => 64];
                }
            }
            PHP,
            var_export($id, true),
            self::shortName($class),
        ));
    }

    /** The file a generated class was written to (for classmap fixtures). */
    public static function fileOf(string $class): string
    {
        $file = new \ReflectionClass($class)->getFileName();
        if (!is_string($file)) {
            throw new \LogicException(sprintf('%s was not declared through %s.', $class, self::class));
        }

        return $file;
    }

    /** @return class-string */
    private static function uniqueClassName(string $seed): string
    {
        $safeSeed = ucfirst((string) preg_replace('/[^A-Za-z0-9]/', '', $seed));

        /** @var class-string $class */
        $class = self::NAMESPACE . '\\Plugin' . $safeSeed . '_' . bin2hex(random_bytes(4));

        return $class;
    }

    private static function shortName(string $class): string
    {
        return substr($class, strrpos($class, '\\') + 1);
    }

    /** @return class-string */
    private static function write(string $class, string $body): string
    {
        $directory = sys_get_temp_dir() . '/waaseyaa_field_type_fixtures';
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create fixture directory %s.', $directory));
        }

        $file = $directory . '/' . self::shortName($class) . '.php';
        file_put_contents($file, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n%s\n",
            self::NAMESPACE,
            $body,
        ));
        require_once $file;

        /** @var class-string $class */
        return $class;
    }
}
