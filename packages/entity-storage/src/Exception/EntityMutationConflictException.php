<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

use Waaseyaa\Entity\Concurrency\EntityMutationConflictException as EntityMutationConflict;

/** @api */
final class EntityMutationConflictException extends EntityMutationConflict {}
