<?php

namespace App\Enums;

/** Where trading concludes when the 7th falls on a Saturday or Sunday. */
enum WeekendTradingPolicy: string
{
    case NextMonday = 'next_monday';
    case PreviousFriday = 'previous_friday';
}
