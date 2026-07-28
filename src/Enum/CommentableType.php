<?php

namespace App\Enum;

enum CommentableType: string
{
    case Article = 'article';
    case Place = 'place';
    case Hike = 'hike';
    case CityVisit = 'city-visit';

    public function publicRoute(): string
    {
        return match ($this) {
            self::Article => 'app_article_show',
            self::Place => 'app_place_show',
            self::Hike => 'app_hike_show',
            self::CityVisit => 'app_city_visit_show',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Article => 'Article',
            self::Place => 'Repérage',
            self::Hike => 'Randonnée',
            self::CityVisit => 'Visite de ville',
        };
    }
}
