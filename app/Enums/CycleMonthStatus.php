<?php

namespace App\Enums;

enum CycleMonthStatus: string
{
    case Pending = 'pending';
    case DeclarationsOpen = 'declarations_open';
    case Trading = 'trading';
    case Closed = 'closed';
}
