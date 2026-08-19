<?php

namespace App\Enums;

enum CycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closing = 'closing';
    case Closed = 'closed';
}
