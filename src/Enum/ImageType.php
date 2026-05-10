<?php

namespace App\Enum;

enum ImageType: string
{
    case Standard = 'standard';
    case Degree360 = '360';
    case Degree180 = '180';
    case Panorama = 'panorama';
    case WideAngle = 'wide_angle';
}
