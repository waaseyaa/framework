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
 * **Canonicalization.** The inventory sorts by skill id and rejects
 * duplicate ids at construction. Sorting is the canonicalization: it is
 * what makes "two inventories over the same skill set are equal" true for
 * every caller, not only for the one that happens to come from
 * `SkillSetParser` (which already sorts). Duplicate rejection is what makes
 * that total — sorting alone cannot canonicalize two entries claiming one
 * id, because `find()` would still pick one silently and `ids()` would
 * still repeat it, which is precisely the ambiguity the type exists to
 * remove. A duplicate cannot arise from the parser (directory names are
 * unique), so it is always a programming error at an in-memory
 * `fromSkills()` callsite and fails closed with an
 * `\InvalidArgumentException` naming the id.
 *
 * This is deliberately NOT a hash or version authority — #2664 owns the
 * single generated-state hash/version engine (see the anchor issue). Two
 * inventories built from the same skill set are `==`-equal because they
 * wrap the same `ParsedSkill` value objects in the same canonical order,
 * which is enough for the regression proof in this issue; it is not a
 * substitute for whatever content-hash mechanism #2664 settles on.
 *
 * @api
 */
final readonly class SkillInventory
{
    /**
     * @param list<ParsedSkill> $skills Already sorted by id and free of duplicates — {@see self::fromSkills()} is the only way in, and it enforces both.
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
        return self::fromSkills($parser->parse());
    }

    /**
     * Wrap an already-parsed skill list directly — the seam tests and
     * any future in-memory caller use instead of standing up a parser.
     *
     * Canonicalizes: the result is sorted by id whatever order the caller
     * passed. See the class docblock for why duplicates fail closed here
     * rather than being resolved by position.
     *
     * @param list<ParsedSkill> $skills
     * @throws \InvalidArgumentException When two skills share an id.
     */
    public static function fromSkills(array $skills): self
    {
        $seen = [];
        foreach ($skills as $skill) {
            if (array_key_exists($skill->id, $seen)) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot build a SkillInventory carrying duplicate skill id "%s"; '
                    . 'a skill id must identify exactly one skill.',
                    $skill->id,
                ));
            }

            $seen[$skill->id] = true;
        }

        // Same comparison SkillSetParser::parse() uses, so a parsed set is
        // already in this order and round-trips unchanged.
        usort($skills, static fn(ParsedSkill $a, ParsedSkill $b): int => strcmp($a->id, $b->id));

        return new self($skills);
    }

    /**
     * @return list<ParsedSkill> sorted by id
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
     * @return list<string> sorted, with no duplicates
     */
    public function ids(): array
    {
        return array_map(static fn(ParsedSkill $skill): string => $skill->id, $this->skills);
    }

    /**
     * The skill with this id, or null when the inventory does not carry one.
     *
     * Unambiguous by construction: duplicate ids never reach an instance.
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
