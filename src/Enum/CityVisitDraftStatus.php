<?php

namespace App\Enum;

enum CityVisitDraftStatus: string
{
    case Draft = 'draft';
    case Finished = 'finished';
    case Converted = 'converted';
    case Archived = 'archived';
}
