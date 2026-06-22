<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

enum HandlerOptionMode
{
    case None;
    case Required;
    case Optional;
    case Array_;
    case Negatable;
}
