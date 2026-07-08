<?php

namespace App\Enum;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case PrivateContent = 'private';
    case Archived = 'archived';
}
