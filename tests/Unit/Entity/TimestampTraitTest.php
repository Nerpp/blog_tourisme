<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Entity\Traits\TimestampableTrait;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TimestampTraitTest extends TestCase
{
    public function testCreatedAtTraitInitializesOnlyWhenEmpty(): void
    {
        $entity = new class {
            use CreatedAtTrait;
        };

        self::assertNull($entity->getCreatedAt());
        $entity->initializeCreatedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $entity->getCreatedAt());

        $fixed = new DateTimeImmutable('2026-01-02 03:04:05');
        $entity->setCreatedAt($fixed);
        $entity->initializeCreatedAt();

        self::assertSame($fixed, $entity->getCreatedAt());
    }

    public function testTimestampableTraitInitializesAndRefreshesTimestamps(): void
    {
        $entity = new class {
            use TimestampableTrait;
        };

        self::assertNull($entity->getCreatedAt());
        self::assertNull($entity->getUpdatedAt());

        $entity->initializeTimestamps();
        $createdAt = $entity->getCreatedAt();
        $updatedAt = $entity->getUpdatedAt();

        self::assertInstanceOf(DateTimeImmutable::class, $createdAt);
        self::assertInstanceOf(DateTimeImmutable::class, $updatedAt);

        $fixedCreatedAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $fixedUpdatedAt = new DateTimeImmutable('2026-01-03 03:04:05');
        $entity
            ->setCreatedAt($fixedCreatedAt)
            ->setUpdatedAt($fixedUpdatedAt)
            ->initializeTimestamps();

        self::assertSame($fixedCreatedAt, $entity->getCreatedAt());
        self::assertSame($fixedUpdatedAt, $entity->getUpdatedAt());

        $entity->refreshUpdatedAt();
        self::assertNotSame($fixedUpdatedAt, $entity->getUpdatedAt());
        self::assertGreaterThanOrEqual($fixedUpdatedAt->getTimestamp(), $entity->getUpdatedAt()?->getTimestamp());
    }
}
