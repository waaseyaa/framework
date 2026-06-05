<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Entity\ClassificationLabelDefinition;

#[CoversClass(ClassificationLabelDefinition::class)]
final class ClassificationLabelDefinitionTest extends TestCase
{
    #[Test]
    public function can_construct_with_empty_values(): void
    {
        $def = new ClassificationLabelDefinition([]);

        self::assertSame('', $def->getLabelId());
        self::assertSame('', $def->getDisplayName());
        self::assertSame(0, $def->getConfidentialityLevel());
    }

    #[Test]
    public function getters_return_set_values(): void
    {
        $def = new ClassificationLabelDefinition([
            'label_id' => 'confidential',
            'display_name' => 'Confidential',
            'confidentiality_level' => 20,
        ]);

        self::assertSame('confidential', $def->getLabelId());
        self::assertSame('Confidential', $def->getDisplayName());
        self::assertSame(20, $def->getConfidentialityLevel());
    }

    #[Test]
    public function all_nine_seed_label_ids_are_valid_identifiers(): void
    {
        $seedLabels = [
            'public', 'internal', 'confidential', 'restricted',
            'nation-confidential', 'nation-sacred',
            'hold-legal', 'hold-research', 'hold-ethics-review',
        ];

        foreach ($seedLabels as $labelId) {
            $def = new ClassificationLabelDefinition(['label_id' => $labelId]);
            self::assertSame($labelId, $def->getLabelId(), "Label '$labelId' should round-trip correctly");
        }
    }

    #[Test]
    public function confidentiality_level_coerces_string_to_int(): void
    {
        $def = new ClassificationLabelDefinition(['confidentiality_level' => '30']);

        self::assertSame(30, $def->getConfidentialityLevel());
    }
}
