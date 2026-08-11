<?php

namespace App\Enum;

enum InstagramPublicationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case PartialFailure = 'partial_failure';
    case Failed = 'failed';
    case NoMedia = 'no_media';
    case LegacySkipped = 'legacy_skipped';
}
