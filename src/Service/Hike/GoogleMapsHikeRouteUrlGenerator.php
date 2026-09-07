<?php

namespace App\Service\Hike;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;

final class GoogleMapsHikeRouteUrlGenerator
{
    public function generate(HikeDraft $hikeDraft): ?string
    {
        $points = $hikeDraft->getPoints()->toArray();
        usort($points, static fn (HikePoint $first, HikePoint $second): int => $first->getPosition() <=> $second->getPosition());

        $points = array_values(array_filter(
            $points,
            static function (HikePoint $point): bool {
                $latitude = $point->getLatitude();
                $longitude = $point->getLongitude();

                return $latitude !== null && $latitude >= -90 && $latitude <= 90
                    && $longitude !== null && $longitude >= -180 && $longitude <= 180;
            },
        ));

        if ($points === []) {
            return null;
        }

        $coordinates = array_map(static fn (HikePoint $point): string => $point->getLatitude().','.$point->getLongitude(), $points);
        if (count($coordinates) === 1) {
            return 'https://www.google.com/maps/search/?api=1&query='.$coordinates[0];
        }

        $origin = array_shift($coordinates);
        $destination = array_pop($coordinates);
        $url = 'https://www.google.com/maps/dir/?api=1&travelmode=walking&origin='.rawurlencode((string) $origin).'&destination='.rawurlencode((string) $destination);

        if ($coordinates !== []) {
            $url .= '&waypoints='.rawurlencode(implode('|', $coordinates));
        }

        return $url;
    }
}
