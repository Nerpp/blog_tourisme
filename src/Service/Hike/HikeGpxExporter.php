<?php

namespace App\Service\Hike;

use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use DOMDocument;

final class HikeGpxExporter
{
    private const CREATOR = 'Blog Tourisme';

    public function isAvailable(HikeDraft $hike): bool
    {
        return count($this->validGpsPoints($hike)) >= 2;
    }

    public function filename(HikeDraft $hike): string
    {
        $slug = (string) ($hike->getSlug() ?: 'randonnee');
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/i', '-', $slug), '-');

        return sprintf('randonnee-%s.gpx', $slug !== '' ? strtolower($slug) : 'randonnee');
    }

    public function export(HikeDraft $hike): string
    {
        $points = $this->validGpsPoints($hike);

        if (count($points) < 2) {
            throw new \InvalidArgumentException('A GPX export requires at least two valid GPS points.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $gpx = $document->createElement('gpx');
        $gpx->setAttribute('version', '1.1');
        $gpx->setAttribute('creator', self::CREATOR);
        $gpx->setAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
        $document->appendChild($gpx);

        $metadata = $document->createElement('metadata');
        $this->appendTextElement($document, $metadata, 'name', $this->plainText($hike->getTitle() ?: 'Randonnée'));
        $gpx->appendChild($metadata);

        foreach ($points as $index => $point) {
            $waypoint = $document->createElement('wpt');
            $this->applyCoordinates($waypoint, $point);
            $this->appendTextElement($document, $waypoint, 'name', $this->pointName($point, $index));
            $this->appendTextElement($document, $waypoint, 'desc', sprintf('Type : %s', $point->getType()->value));
            $gpx->appendChild($waypoint);
        }

        $route = $document->createElement('rte');
        $this->appendTextElement($document, $route, 'name', sprintf('%s - étapes', $this->plainText($hike->getTitle() ?: 'Randonnée')));

        foreach ($points as $index => $point) {
            $routePoint = $document->createElement('rtept');
            $this->applyCoordinates($routePoint, $point);
            $this->appendTextElement($document, $routePoint, 'name', sprintf('Étape %d - %s', $index + 1, $this->plainText($point->getTitle() ?: 'Point')));
            $route->appendChild($routePoint);
        }

        $gpx->appendChild($route);

        return $document->saveXML() ?: '';
    }

    /**
     * @return list<HikePoint>
     */
    public function validGpsPoints(HikeDraft $hike): array
    {
        $points = [];

        foreach ($hike->getPoints() as $point) {
            $latitude = $point->getLatitude();
            $longitude = $point->getLongitude();

            if ($latitude === null || $longitude === null) {
                continue;
            }

            if (!$this->validCoordinate($latitude, -90, 90) || !$this->validCoordinate($longitude, -180, 180)) {
                continue;
            }

            $points[] = $point;
        }

        usort(
            $points,
            static fn (HikePoint $first, HikePoint $second): int => $first->getPosition() <=> $second->getPosition()
                ?: ($first->getId() ?? 0) <=> ($second->getId() ?? 0),
        );

        return $points;
    }

    private function applyCoordinates(\DOMElement $element, HikePoint $point): void
    {
        $element->setAttribute('lat', $this->formatCoordinate((float) $point->getLatitude()));
        $element->setAttribute('lon', $this->formatCoordinate((float) $point->getLongitude()));
    }

    private function appendTextElement(DOMDocument $document, \DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }

    private function formatCoordinate(float $coordinate): string
    {
        return rtrim(rtrim(sprintf('%.7F', $coordinate), '0'), '.');
    }

    private function validCoordinate(float $coordinate, float $min, float $max): bool
    {
        return is_finite($coordinate) && $coordinate >= $min && $coordinate <= $max;
    }

    private function pointName(HikePoint $point, int $index): string
    {
        return $this->plainText($point->getTitle() ?: sprintf('Étape %d', $index + 1));
    }

    private function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
