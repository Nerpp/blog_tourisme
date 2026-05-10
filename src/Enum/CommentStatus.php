<?php

namespace App\Enum;

enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
    case Deleted = 'deleted';
}
