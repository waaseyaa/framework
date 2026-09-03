<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillInventory;
use Waaseyaa\Bimaaji\Install\SkillResourceException;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(SkillInventory::class)]
final class SkillInventoryTest extends TestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            new Filesystem()->remove($this->tempDir);
        }
    }

    #[Test]
    public function allReturnsExactlyTheWrappedSkillsInOrder(): void
    {
        $skills = InstallSkillFixtures::all();
        $inventory = SkillInventory::fromSkills($skills);

        self::assertSame($skills, $inventory->all());
    }

    #[Test]
    public function countReflectsTheNumberOfSkills(): void
    {
        self::assertSame(3, SkillInventory::fromSkills(InstallSkillFixtures::all())->count());
        self::assertSame(0, SkillInventory::fromSkills([])->count());
    }

    #[Test]
    public function idsReturnsEveryIdInTheStoredOrder(): void
    {
        self::assertSame(
            ['skill-alpha', 'skill-beta', 'skill-gamma'],
            SkillInventory::fromSkills(InstallSkillFixtures::all())->ids(),
        );
    }

    #[Test]
    public function findReturnsTheMatchingSkillById(): void
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all());

        $beta = $inventory->find('skill-beta');

        self::assertNotNull($beta);
        self::assertSame('skill-beta', $beta->id);
    }

    #[Test]
    public function findReturnsNullForAnUnknownId(): void
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all());

        self::assertNull($inventory->find('does-not-exist'));
    }

    #[Test]
    public function fromSkillsSortsByIdSoTheInventoryIsCanonicalRegardlessOfInputOrder(): void // #2660 Part A repair
    {
        // fromSkills() documented "sorted by id" but only ever restated the
        // caller's order, so two inventories over the same skill set could
        // disagree on `all()`/`ids()` purely by construction order. Sorting
        // here is what makes the documented promise true for every caller,
        // not only for the one that happens to come from SkillSetParser.
        $shuffled = [
            InstallSkillFixtures::gamma(),
            InstallSkillFixtures::alpha(),
            InstallSkillFixtures::beta(),
        ];

        $inventory = SkillInventory::fromSkills($shuffled);

        self::assertSame(['skill-alpha', 'skill-beta', 'skill-gamma'], $inventory->ids());
        self::assertEquals(InstallSkillFixtures::all(), $inventory->all());
    }

    #[Test]
    public function twoInventoriesOverTheSameSkillSetAreEqualWhateverTheInputOrder(): void // #2660 Part A repair
    {
        self::assertEquals(
            SkillInventory::fromSkills(InstallSkillFixtures::all()),
            SkillInventory::fromSkills([
                InstallSkillFixtures::beta(),
                InstallSkillFixtures::gamma(),
                InstallSkillFixtures::alpha(),
            ]),
        );
    }

    #[Test]
    public function duplicateIdsAreRejectedRatherThanSilentlyResolvedByFind(): void // #2660 Part A repair
    {
        // Sorting alone cannot canonicalize two entries claiming one id:
        // find() would still pick one silently and ids() would still repeat
        // it. Fail closed at construction, naming the id.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate skill id "skill-alpha"/');

        SkillInventory::fromSkills([
            InstallSkillFixtures::alpha(),
            InstallSkillFixtures::beta(),
            InstallSkillFixtures::alpha(),
        ]);
    }

    #[Test]
    public function aDuplicateIdIsRejectedEvenWhenTheTwoEntriesDifferInEveryOtherField(): void // #2660 Part A repair
    {
        // The dangerous case: not a copy of the same skill, but two DIFFERENT
        // bodies competing for one id — exactly what find() used to resolve
        // by position.
        $this->expectException(\InvalidArgumentException::class);

        SkillInventory::fromSkills([
            InstallSkillFixtures::alpha(),
            new ParsedSkill(
                id: 'skill-alpha',
                name: 'Impostor',
                description: 'A different skill claiming the same id.',
                frontmatter: [],
                body: 'Different body.',
            ),
        ]);
    }

    #[Test]
    public function fromParserWrapsTheParsersOutputWithoutReimplementingDiscovery(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_skill_inventory_test_' . uniqid();
        mkdir($this->tempDir . '/skill-one', 0o755, true);
        mkdir($this->tempDir . '/skill-two', 0o755, true);
        file_put_contents(
            $this->tempDir . '/skill-one/SKILL.md',
            "---\nname: skill-one\ndescription: First\n---\n\nBody one.",
        );
        file_put_contents(
            $this->tempDir . '/skill-two/SKILL.md',
            "---\nname: skill-two\ndescription: Second\n---\n\nBody two.",
        );

        $parser = new SkillSetParser($this->tempDir);
        $inventory = SkillInventory::fromParser($parser);

        // A fresh parse() call returns new ParsedSkill instances (value
        // objects, compared by value with assertEquals), so this proves the
        // inventory wraps the parser's output rather than diverging from
        // it — not that the two calls returned the identical objects.
        self::assertEquals($parser->parse(), $inventory->all());
        self::assertSame(['skill-one', 'skill-two'], $inventory->ids());
        self::assertSame(2, $inventory->count());
    }

    #[Test]
    public function fromParserPropagatesSkillResourceExceptionForAMissingDirectory(): void
    {
        $this->expectException(SkillResourceException::class);

        SkillInventory::fromParser(new SkillSetParser(sys_get_temp_dir() . '/waaseyaa_never_exists_' . uniqid()));
    }
}
