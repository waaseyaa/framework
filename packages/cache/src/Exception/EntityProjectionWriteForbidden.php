<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Exception;

/** Raised before an activated cache boundary can retain an entity graph. @api */
final class EntityProjectionWriteForbidden extends \LogicException {}
