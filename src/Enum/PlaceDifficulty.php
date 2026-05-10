<?php

namespace App\Enum;

enum PlaceDifficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
    case Unknown = 'unknown';
}
