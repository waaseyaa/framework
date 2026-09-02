<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * The one enumeration of the framework's canonical skill set that every
 * `bimaaji:install` consumer reads.
 *
 * `SkillSetParser::parse()` already IS the single globber of
 * `resources/skills/<id>/SKILL.md` — this type does not reimplement or
 * duplicate that parsing. What it adds is a typed collection over the
 * result, so a caller that needs "does skill X exist", "what are all the
 * ids", or "how many skills are there" does not each write its own loop
 * over a raw `list<ParsedSkill>`, and so a future consumer (e.g. #2664's
 * generated-state verification, or #2660's own capability-matched
 * delivery once its open questions are decided) has one typed seam to
 * depend on instead of re-deriving enumeration from the parser again.
 *
 * This is deliberately NOT a hash or version authority — #2664 owns the
 * single generated-state hash/version engine (see the anchor issue). Two
 * inventories built from the same skill set are `==`-equal because they
 * wrap the same `ParsedSkill` value objects, which is enough for the
 * regression proof in this issue; it is not a substitute for whatever
 * content-hash mechanism #2664 settles on.
 *
 * @api
 */
final readonly class SkillInventory
{
    /**
     * @param list<ParsedSkill> $skills Sorted by id (the order {@see SkillSetParser::parse()} already guarantees).
     */
    private function __construct(private array $skills) {}

    /**
     * Parse the skill set via `$parser` and wrap it. Calls `parse()`
     * exactly once.
     *
     * @throws SkillResourceException See {@see SkillSetParser::parse()}.
     */
    public static function fromParser(SkillSetParser $parser): self
    {
        return new self($parser->parse());
    }

    /**
     * Wrap an already-parsed skill list directly — the seam tests and
     * any future in-memory caller use instead of standing up a parser.
     *
     * @param list<ParsedSkill> $skills
     */
    public static function fromSkills(array $skills): self
    {
        return new self($skills);
    }

    /**
     * @return list<ParsedSkill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    public function count(): int
    {
        return count($this->skills);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn(ParsedSkill $skill): string => $skill->id, $this->skills);
    }

    /**
     * The skill with this id, or null when the inventory does not carry one.
     */
    public function find(string $id): ?ParsedSkill
    {
        foreach ($this->skills as $skill) {
            if ($skill->id === $id) {
                return $skill;
            }
        }

        return null;
    }
}
