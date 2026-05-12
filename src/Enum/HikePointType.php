<?php

namespace App\Enum;

enum HikePointType: string
{
    case Start = 'start';
    case Interest = 'interest';
    case Viewpoint = 'viewpoint';
    case Photo = 'photo';
    case Water = 'water';
    case Danger = 'danger';
    case Rest = 'rest';
    case End = 'end';
    case Other = 'other';
}
