<?php

namespace App\Service\Social;

use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Service\Facebook\FacebookPublicationScheduler;
use App\Service\Instagram\InstagramPublicationScheduler;

final readonly class SocialPublicationCoordinator
{
    public function __construct(
        private InstagramPublicationScheduler $instagramPublicationScheduler,
        private FacebookPublicationScheduler $facebookPublicationScheduler,
    ) {
    }

    public function prepareFirstPublications(HikeDraft|CityVisitDraft $content): PreparedSocialPublications
    {
        return new PreparedSocialPublications(
            $this->instagramPublicationScheduler->prepareFirstPublication($content),
            $this->facebookPublicationScheduler->prepareFirstPublication($content),
        );
    }

    public function dispatchAfterCommit(PreparedSocialPublications $publications): void
    {
        if (null !== $publications->instagram) {
            $this->instagramPublicationScheduler->dispatchAfterCommit($publications->instagram);
        }

        if (null !== $publications->facebook) {
            $this->facebookPublicationScheduler->dispatchAfterCommit($publications->facebook);
        }
    }
}
