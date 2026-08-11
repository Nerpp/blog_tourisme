<?php

namespace App\Enum;

enum InstagramPublicationBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
}
