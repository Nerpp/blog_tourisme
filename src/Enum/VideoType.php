<?php

namespace App\Enum;

enum VideoType: string
{
    case Local = 'local';
    case Youtube = 'youtube';
    case Vimeo = 'vimeo';
    case Dailymotion = 'dailymotion';
    case External = 'external';
}
