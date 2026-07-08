<?php

namespace App\Enum;

enum DestinationType: string
{
    case Country = 'country';
    case Region = 'region';
    case Department = 'department';
    case City = 'city';
    case Area = 'area';
}
