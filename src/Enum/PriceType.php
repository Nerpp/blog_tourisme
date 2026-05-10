<?php

namespace App\Enum;

enum PriceType: string
{
    case Free = 'free';
    case Paid = 'paid';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
