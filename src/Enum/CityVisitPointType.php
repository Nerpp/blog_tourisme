<?php

namespace App\Enum;

enum CityVisitPointType: string
{
    case Start = 'start';
    case Monument = 'monument';
    case Viewpoint = 'viewpoint';
    case Museum = 'museum';
    case Church = 'church';
    case Square = 'square';
    case Restaurant = 'restaurant';
    case Photo = 'photo';
    case Parking = 'parking';
    case End = 'end';
    case Other = 'other';
}
