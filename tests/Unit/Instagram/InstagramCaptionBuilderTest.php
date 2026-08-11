<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Service\Instagram\InstagramCaptionBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstagramCaptionBuilderTest extends TestCase
{
    public function testItBuildsAHikeCaptionFromExistingEditorialFields(): void
    {
        $geographicDestination = (new Destination())->setName('Casteil');
        $hike = (new HikeDraft())
            ->setTitle('Boucle du Canigó')
            ->setNotes("  Un sentier entre forêt et crêtes.\nÀ parcourir au lever du jour.  ")
            ->setGeographicDestination($geographicDestination)
            ->setDetectedCommuneName(' Commune détectée ignorée ')
            ->setDestination((new Destination())->setName('Destination éditoriale ignorée'));

        self::assertSame(<<<'CAPTION'
🥾 Boucle du Canigó

Un sentier entre forêt et crêtes.
À parcourir au lever du jour.

📍 Casteil

À découvrir sur Estela Exploration.

Publication 2/3

#EstelaExploration #Randonnee #Nature
CAPTION, (new InstagramCaptionBuilder())->build($hike, 2, 3));
    }

    public function testItBuildsACityVisitCaptionWithASoberFallbackWhenNotesAreMissing(): void
    {
        $cityVisit = (new CityVisitDraft())
            ->setTitle('Le centre ancien')
            ->setGeographicDestination((new Destination())->setName('Perpignan'));

        self::assertSame(<<<'CAPTION'
🏘️ Le centre ancien

📍 Perpignan

À découvrir sur Estela Exploration.

#EstelaExploration #VisiteDeVille #Patrimoine
CAPTION, (new InstagramCaptionBuilder())->build($cityVisit));
    }

    public function testItFallsBackToTheEditorialDestinationAndOmitsAnUnavailableLocation(): void
    {
        $builder = new InstagramCaptionBuilder();
        $cityVisit = (new CityVisitDraft())
            ->setTitle('Balade urbaine')
            ->setDestination((new Destination())->setName(' Catalogne '));

        self::assertStringContainsString('📍 Catalogne', $builder->build($cityVisit));

        $cityVisit->setDestination(null);
        self::assertStringNotContainsString('📍', $builder->build($cityVisit));
    }

    public function testItUsesTheDetectedCommuneOnlyAfterBothDestinationRelations(): void
    {
        $cityVisit = (new CityVisitDraft())
            ->setTitle('Balade urbaine')
            ->setDetectedCommuneName(' Collioure ');

        self::assertStringContainsString('📍 Collioure', (new InstagramCaptionBuilder())->build($cityVisit));
    }

    public function testItLimitsLongUtf8NotesWhilePreservingTheRequiredFooter(): void
    {
        $hike = (new HikeDraft())
            ->setTitle('Longue randonnée')
            ->setNotes(str_repeat('Échappée pyrénéenne. ', 180))
            ->setGeographicDestination((new Destination())->setName('Cerdagne'));

        $caption = (new InstagramCaptionBuilder())->build($hike, 1, 2);

        self::assertLessThanOrEqual(InstagramCaptionBuilder::MAX_CAPTION_LENGTH, mb_strlen($caption));
        self::assertStringContainsString('…', $caption);
        self::assertStringContainsString('À découvrir sur Estela Exploration.', $caption);
        self::assertStringContainsString('Publication 1/2', $caption);
        self::assertStringEndsWith('#EstelaExploration #Randonnee #Nature', $caption);
    }

    #[DataProvider('invalidBatchProvider')]
    public function testItRejectsAnInvalidBatchPosition(int $batchNumber, int $batchCount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new InstagramCaptionBuilder())->build(
            (new HikeDraft())->setTitle('Randonnée'),
            $batchNumber,
            $batchCount,
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidBatchProvider(): iterable
    {
        yield 'zero batch number' => [0, 1];
        yield 'zero batch count' => [1, 0];
        yield 'batch after total' => [3, 2];
    }
}
