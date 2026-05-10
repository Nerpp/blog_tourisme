<?php

namespace App\Enum;

enum MediaRole: string
{
    case Cover = 'cover';
    case Gallery = 'gallery';
    case Content = 'content';
    case Inline = 'inline';
    case Seo = 'seo';
    case MapPreview = 'map_preview';
    case Immersive = 'immersive';
}
