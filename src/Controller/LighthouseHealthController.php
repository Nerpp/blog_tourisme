<?php

namespace App\Controller;

use App\Command\AssertLighthouseDatabaseCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class LighthouseHealthController extends AbstractController
{
    #[Route('/_lighthouse/health', name: 'app_audit_health', methods: ['GET'])]
    public function __invoke(KernelInterface $kernel, Connection $connection): JsonResponse
    {
        $database = $connection->getDatabase();
        if (
            $kernel->getEnvironment() !== AssertLighthouseDatabaseCommand::EXPECTED_ENVIRONMENT
            || $database !== AssertLighthouseDatabaseCommand::EXPECTED_DATABASE
        ) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'environment' => AssertLighthouseDatabaseCommand::EXPECTED_ENVIRONMENT,
            'database' => AssertLighthouseDatabaseCommand::EXPECTED_DATABASE,
            'catalog' => 'lighthouse-pages-v1',
        ]);
    }
}
