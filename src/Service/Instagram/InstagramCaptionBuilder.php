<?php

namespace App\Service\Instagram;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;

final readonly class InstagramCaptionBuilder
{
    public const MAX_CAPTION_LENGTH = 2_200;

    public function build(HikeDraft|CityVisitDraft $content, int $batchNumber = 1, int $batchCount = 1): string
    {
        if ($batchCount < 1 || $batchNumber < 1 || $batchNumber > $batchCount) {
            throw new \InvalidArgumentException('Le numéro de lot Instagram doit être compris dans le nombre total de lots.');
        }

        $isHike = $content instanceof HikeDraft;
        $headline = sprintf('%s %s', $isHike ? '🥾' : '🏘️', trim((string) $content->getTitle()));
        $notes = trim((string) $content->getNotes());
        $destination = $this->reliableDestination($content);
        $leadingParts = [$headline];
        $destinationPart = $destination !== null ? '📍 '.$destination : null;
        $footerParts = ['À découvrir sur Estela Exploration.'];

        if ($batchCount > 1) {
            $footerParts[] = sprintf('Publication %d/%d', $batchNumber, $batchCount);
        }

        $footerParts[] = $isHike
            ? '#EstelaExploration #Randonnee #Nature'
            : '#EstelaExploration #VisiteDeVille #Patrimoine';

        $partsWithoutNotes = $leadingParts;
        if ($destinationPart !== null) {
            $partsWithoutNotes[] = $destinationPart;
        }
        $partsWithoutNotes = [...$partsWithoutNotes, ...$footerParts];

        if ($notes !== '') {
            $availableNotesLength = self::MAX_CAPTION_LENGTH
                - mb_strlen($this->compose($partsWithoutNotes))
                - 2;
            if ($availableNotesLength > 0) {
                $leadingParts[] = $this->truncateWithEllipsis($notes, $availableNotesLength);
            }
        }

        if ($destinationPart !== null) {
            $leadingParts[] = $destinationPart;
        }

        $caption = $this->compose([...$leadingParts, ...$footerParts]);
        if (mb_strlen($caption) <= self::MAX_CAPTION_LENGTH) {
            return $caption;
        }

        return $this->truncateLeadingContentWhilePreservingFooter($leadingParts, $footerParts);
    }

    private function reliableDestination(HikeDraft|CityVisitDraft $content): ?string
    {
        foreach ([$content->getGeographicDestination(), $content->getDestination()] as $destination) {
            if (!$destination instanceof Destination) {
                continue;
            }

            $name = trim((string) $destination->getName());
            if ($name !== '') {
                return $name;
            }
        }

        $commune = trim((string) $content->getDetectedCommuneName());

        return $commune !== '' ? $commune : null;
    }

    /** @param list<string> $parts */
    private function compose(array $parts): string
    {
        return implode("\n\n", $parts);
    }

    /**
     * @param list<string> $leadingParts
     * @param non-empty-list<string> $footerParts
     */
    private function truncateLeadingContentWhilePreservingFooter(array $leadingParts, array $footerParts): string
    {
        $footer = $this->compose($footerParts);
        $availableLeadingLength = self::MAX_CAPTION_LENGTH - mb_strlen($footer) - 2;
        $leading = $this->truncateWithEllipsis($this->compose($leadingParts), max(0, $availableLeadingLength));

        return $leading !== '' ? $leading."\n\n".$footer : $footer;
    }

    private function truncateWithEllipsis(string $value, int $maximumLength): string
    {
        if ($maximumLength <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $maximumLength) {
            return $value;
        }

        if ($maximumLength === 1) {
            return '…';
        }

        return rtrim(mb_substr($value, 0, $maximumLength - 1)).'…';
    }
}
