<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Advisory;

use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;
use Waaseyaa\EntityStorage\SaveContext;

/** Refuses a save until every current advisory has an exact acknowledgement. @api */
final class SaveAdvisoryGate
{
    /** @param array<int, mixed> $advisories */
    public static function requireAcknowledged(array $advisories, SaveContext $context): void
    {
        $unique = [];
        foreach ($advisories as $advisory) {
            if (!$advisory instanceof SaveAdvisory) {
                throw new \InvalidArgumentException('SaveAdvisoryGate requires only SaveAdvisory values.');
            }
            if (!$context->acknowledgesSaveAdvisory($advisory->acknowledgement)) {
                $unique[$advisory->acknowledgement] = $advisory;
            }
        }
        if ($unique === []) {
            return;
        }

        $pending = array_values($unique);
        usort($pending, static fn(SaveAdvisory $left, SaveAdvisory $right): int => [
            $left->code,
            $left->field,
            $left->acknowledgement,
        ] <=> [
            $right->code,
            $right->field,
            $right->acknowledgement,
        ]);

        throw new SaveAdvisoryAcknowledgementRequiredException($pending);
    }
}
