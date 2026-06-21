<?php

namespace App\Tests\Unit\Command;

use App\Command\SanitizeMediaMetadataCommand;
use App\Repository\MediaAssetRepository;
use App\Service\Media\ImageMetadataSanitizer;
use App\Tests\Support\TestImageFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class SanitizeMediaMetadataCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/sanitize-command-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/public/uploads/avatars/nested', 0775, true);
        mkdir($this->workspace.'/public/uploads/demo', 0775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->workspace);
    }

    public function testOptionalAvatarAndDemoScanIncludesImagesRecursivelyAndIgnoresOtherFiles(): void
    {
        $avatar = TestImageFactory::createJpeg(
            $this->workspace.'/public/uploads/avatars/nested',
            32,
            32,
            'avatar.jpg',
        );
        $demo = TestImageFactory::createPng(
            $this->workspace.'/public/uploads/demo',
            40,
            20,
            'demo.png',
        );
        file_put_contents($this->workspace.'/public/uploads/demo/readme.txt', 'not an image');

        $repository = $this->createStub(MediaAssetRepository::class);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $parameterBag = new ParameterBag(['kernel.project_dir' => $this->workspace]);
        $command = new SanitizeMediaMetadataCommand(
            $repository,
            new ImageMetadataSanitizer($parameterBag),
            $entityManager,
            $this->workspace,
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--include-avatars' => true,
            '--include-demo' => true,
            '--dry-run' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('/uploads/avatars/nested/'.basename($avatar).' serait nettoyé', $tester->getDisplay());
        self::assertStringContainsString('/uploads/demo/'.basename($demo).' serait nettoyé', $tester->getDisplay());
        self::assertStringNotContainsString('readme.txt', $tester->getDisplay());
        self::assertStringContainsString('Rapport : 2 analysé(s), 2 nettoyé(s), 0 ignoré(s), 0 erreur(s).', $tester->getDisplay());
    }
}
