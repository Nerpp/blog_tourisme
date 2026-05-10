<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ModerationKeyword;
use App\Enum\ModerationKeywordType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class ModerationKeywordFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $keywords = [
            ModerationKeywordType::Review->value => ['arnaque', 'douteux', 'bizarre'],
            ModerationKeywordType::Spam->value => ['casino', 'crypto facile', 'argent rapide', 'prêt immédiat'],
            ModerationKeywordType::Blocked->value => ['insulte-test', 'contenu-interdit-test'],
        ];

        foreach ($keywords as $type => $items) {
            foreach ($items as $item) {
                $keyword = (new ModerationKeyword())
                    ->setKeyword($item)
                    ->setType(ModerationKeywordType::from($type))
                    ->setEnabled(true);

                $manager->persist($keyword);
            }
        }

        $manager->flush();
    }
}
