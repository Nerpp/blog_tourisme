<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AvatarUploadGitIgnoreTest extends TestCase
{
    public function testUploadedAvatarFilesAreIgnoredButGitkeepIsTracked(): void
    {
        $gitignore = file_get_contents(__DIR__.'/../../.gitignore');
        self::assertIsString($gitignore);

        self::assertStringContainsString('public/uploads/avatars/*', $gitignore);
        self::assertStringContainsString('!public/uploads/avatars/.gitkeep', $gitignore);
        self::assertFileExists(__DIR__.'/../../public/uploads/avatars/.gitkeep');
    }
}
