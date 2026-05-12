<?php

namespace App\Enum;

enum HikeDraftStatus: string
{
    case Draft = 'draft';
    case Finished = 'finished';
    case Converted = 'converted';
    case Archived = 'archived';
}
