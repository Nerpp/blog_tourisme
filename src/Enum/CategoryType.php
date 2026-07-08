<?php

namespace App\Enum;

enum CategoryType: string
{
    case Article = 'article';
    case Place = 'place';
    case Both = 'both';
}
