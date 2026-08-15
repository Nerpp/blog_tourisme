<?php

namespace App\Enum;

enum FacebookPublicationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
}
