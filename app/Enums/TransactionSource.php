<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Trading = 'trading';
    case Import = 'import';
    case System = 'system';
}
