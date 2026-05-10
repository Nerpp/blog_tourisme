<?php

namespace App\Enum;

enum CommentReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Harassment = 'harassment';
    case Inappropriate = 'inappropriate';
    case FalseInformation = 'false_information';
    case Other = 'other';
}
