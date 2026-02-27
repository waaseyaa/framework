<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

enum EntityEvents: string
{
    case PRE_SAVE = 'aurora.entity.pre_save';
    case POST_SAVE = 'aurora.entity.post_save';
    case PRE_DELETE = 'aurora.entity.pre_delete';
    case POST_DELETE = 'aurora.entity.post_delete';
    case POST_LOAD = 'aurora.entity.post_load';
    case PRE_CREATE = 'aurora.entity.pre_create';
}
