<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy;

use Waaseyaa\Entity\EntityInterface;

/**
 * Living/deceased axis for {@see GenealogyPerson}.
 *
 * Stored {@see GenealogyPerson} `is_living` is authoritative; callers may still use
 * death-date heuristics when seeding or normalizing before save.
 *
 * @api
 */
final class GenealogyLivingSemantics
{
    public static function effectiveIsLiving(EntityInterface $entity): bool
    {
        if ($entity->getEntityTypeId() !== 'genealogy_person') {
            return false;
        }

        $v = $entity->get('is_living');
        if ($v === null) {
            return self::inferFromDates($entity);
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((int) $v) === 1;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes'], true);
    }

    /**
     * Conservative default when `is_living` is unset: living unless a death date is present.
     */
    public static function inferFromDates(EntityInterface $entity): bool
    {
        $death = trim((string) ($entity->get('death_date') ?? ''));

        return $death === '';
    }
}
