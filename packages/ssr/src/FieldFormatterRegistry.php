<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\SSR\Formatter\BooleanFormatter;
use Waaseyaa\SSR\Formatter\DateFormatter;
use Waaseyaa\SSR\Formatter\EntityReferenceFormatter;
use Waaseyaa\SSR\Formatter\HtmlFormatter;
use Waaseyaa\SSR\Formatter\ImageFormatter;
use Waaseyaa\SSR\Formatter\PlainTextFormatter;

final class FieldFormatterRegistry
{
    /** @var array<string, class-string<FieldFormatterInterface>> */
    private array $formatters = [];

    /** @var array<class-string<FieldFormatterInterface>, FieldFormatterInterface> */
    private array $instances = [];

    /**
     * @param array<string, class-string<FieldFormatterInterface>> $manifestFormatters
     */
    public function __construct(array $manifestFormatters = [])
    {
        $this->formatters = [
            'string' => PlainTextFormatter::class,
            'text' => PlainTextFormatter::class,
            'text_long' => HtmlFormatter::class,
            'datetime' => DateFormatter::class,
            'timestamp' => DateFormatter::class,
            'boolean' => BooleanFormatter::class,
            'entity_reference' => EntityReferenceFormatter::class,
            'image' => ImageFormatter::class,
        ];

        foreach ($manifestFormatters as $fieldType => $class) {
            if (is_string($fieldType) && is_string($class)) {
                $this->formatters[$fieldType] = $class;
            }
        }
    }

    public function get(string $fieldType): FieldFormatterInterface
    {
        $class = $this->formatters[$fieldType] ?? PlainTextFormatter::class;

        if (!isset($this->instances[$class])) {
            $instance = class_exists($class) ? new $class() : new PlainTextFormatter();
            if (!$instance instanceof FieldFormatterInterface) {
                $instance = new PlainTextFormatter();
            }
            $this->instances[$class] = $instance;
        }

        return $this->instances[$class];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function format(string $fieldType, mixed $value, array $settings = []): string
    {
        return $this->get($fieldType)->format($value, $settings);
    }
}
