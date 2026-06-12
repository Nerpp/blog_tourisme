<?php

namespace App\Enum;

enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
    case HiddenPendingReport = 'report_hidden';
    case HiddenByAdmin = 'admin_hidden';
    case Deleted = 'deleted';
}
