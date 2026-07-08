<?php

declare(strict_types=1);

namespace App\DataFixtures;

trait TestFixtureGroup
{
    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
