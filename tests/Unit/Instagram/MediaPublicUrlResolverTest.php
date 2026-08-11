<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\MediaAsset;
use App\Service\Instagram\MediaPublicUrlResolver;
use App\Service\Seo\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

final class MediaPublicUrlResolverTest extends TestCase
{
    public function testItPrefersTheLargestWebpThenItsFallback(): void
    {
        $media = (new MediaAsset())->setVariants([
            'large' => [
                'webp' => '/uploads/media/large.webp',
                'fallback' => '/uploads/media/large.jpg',
            ],
            'medium' => ['webp' => '/uploads/media/medium.webp'],
        ]);

        self::assertSame(
            'https://estela-exploration.fr/uploads/media/large.webp',
            $this->resolver()->resolve($media),
        );

        $media->setVariants([
            'large' => ['fallback' => '/uploads/media/large.jpg'],
            'medium' => ['webp' => '/uploads/media/medium.webp'],
        ]);

        self::assertSame(
            'https://estela-exploration.fr/uploads/media/large.jpg',
            $this->resolver()->resolve($media),
        );
    }

    public function testItUsesSmallerVariantsBeforeAssetLevelFallbacks(): void
    {
        $media = (new MediaAsset())
            ->setVariants([
                'large' => ['webp' => ''],
                'medium' => ['webp' => '/uploads/media/medium.webp'],
                'thumb' => ['webp' => '/uploads/media/thumb.webp'],
            ])
            ->setFilePath('/uploads/media/source.jpg')
            ->setExternalUrl('https://cdn.example.test/external.jpg')
            ->setThumbnailPath('/uploads/media/manual-thumb.jpg');

        self::assertSame(
            'https://estela-exploration.fr/uploads/media/medium.webp',
            $this->resolver()->resolve($media),
        );
    }

    public function testAssetLevelFallbackOrderIsFileThenExternalThenThumbnail(): void
    {
        $resolver = $this->resolver();
        $media = (new MediaAsset())
            ->setFilePath('/uploads/media/source.jpg')
            ->setExternalUrl('https://cdn.example.test/external.jpg')
            ->setThumbnailPath('/uploads/media/manual-thumb.jpg');

        self::assertSame('https://estela-exploration.fr/uploads/media/source.jpg', $resolver->resolve($media));

        $media->setFilePath(null);
        self::assertSame('https://cdn.example.test/external.jpg', $resolver->resolve($media));

        $media->setExternalUrl(null);
        self::assertSame('https://estela-exploration.fr/uploads/media/manual-thumb.jpg', $resolver->resolve($media));
    }

    public function testItSkipsUnsafeCandidatesAndCanUseTheNextHttpsCandidate(): void
    {
        $media = (new MediaAsset())
            ->setVariants(['large' => ['webp' => 'https://user@cdn.example.test/insecure.webp']])
            ->setFilePath('https://cdn.example.test/image.jpg#private-fragment')
            ->setExternalUrl('https://cdn.example.test/image.jpg');

        self::assertSame('https://cdn.example.test/image.jpg', $this->resolver()->resolve($media));
    }

    public function testItNeverReturnsAPlaceholder(): void
    {
        $media = (new MediaAsset())
            ->setVariants(['large' => ['webp' => '/images/placeholders/destination-card-placeholder.webp']])
            ->setFilePath('/uploads/media/real-image.jpg');

        self::assertSame(
            'https://estela-exploration.fr/uploads/media/real-image.jpg',
            $this->resolver()->resolve($media),
        );

        $media->setFilePath(null);
        self::assertNull($this->resolver()->resolve($media));
    }

    public function testItReturnsNullWithoutAnHttpsPublicImage(): void
    {
        self::assertNull($this->resolver('http://localhost')->resolve(
            (new MediaAsset())->setFilePath('/uploads/media/local.jpg'),
        ));
        self::assertNull($this->resolver()->resolve(new MediaAsset()));
    }

    private function resolver(string $publicBaseUrl = 'https://estela-exploration.fr'): MediaPublicUrlResolver
    {
        return new MediaPublicUrlResolver(new PublicUrlGenerator(
            $this->createStub(RouterInterface::class),
            $publicBaseUrl,
        ));
    }
}
