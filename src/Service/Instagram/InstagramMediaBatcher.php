<?php

namespace App\Service\Instagram;

final readonly class InstagramMediaBatcher
{
    public const MAX_MEDIA_PER_BATCH = 10;

    /**
     * @param list<InstagramMedia> $media
     *
     * @return list<list<InstagramMedia>>
     */
    public function batch(array $media): array
    {
        if ($media === []) {
            return [];
        }

        /** @var list<list<InstagramMedia>> $batches */
        $batches = array_chunk($media, self::MAX_MEDIA_PER_BATCH);

        return $batches;
    }
}
