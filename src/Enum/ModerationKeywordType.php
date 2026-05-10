<?php

namespace App\Enum;

enum ModerationKeywordType: string
{
    case Review = 'review';
    case Spam = 'spam';
    case Blocked = 'blocked';
}
