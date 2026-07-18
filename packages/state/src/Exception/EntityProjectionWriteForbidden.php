<?php

declare(strict_types=1);

namespace Waaseyaa\State\Exception;

/** Raised before an activated state boundary can retain an entity graph. @api */
final class EntityProjectionWriteForbidden extends \LogicException {}
