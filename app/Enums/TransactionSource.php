<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Trading = 'trading';
    case Import = 'import';
    case System = 'system';

    /** Money that arrived through the payment gateway rather than across the table. */
    case Gateway = 'gateway';
}
