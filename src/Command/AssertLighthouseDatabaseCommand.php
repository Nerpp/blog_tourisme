<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:lighthouse:assert-safe-database',
    description: 'Refuse toute préparation Lighthouse hors de la base de test app_test.',
)]
final class AssertLighthouseDatabaseCommand extends Command
{
    public const string EXPECTED_ENVIRONMENT = 'test';
    public const string EXPECTED_DATABASE = 'app_test';

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $this->kernel->getEnvironment();
        $database = $this->connection->getDatabase();

        if ($environment !== self::EXPECTED_ENVIRONMENT || $database !== self::EXPECTED_DATABASE) {
            $output->writeln(sprintf(
                '<error>Refus de préparer Lighthouse : environnement "%s", base "%s". Attendu : environnement "%s", base "%s".</error>',
                $environment,
                $database ?? '(inconnue)',
                self::EXPECTED_ENVIRONMENT,
                self::EXPECTED_DATABASE,
            ));
            $output->writeln('<error>La base de développement ne doit jamais être préparée par Lighthouse.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Base Lighthouse sûre confirmée : environnement %s, base %s.</info>',
            self::EXPECTED_ENVIRONMENT,
            self::EXPECTED_DATABASE,
        ));

        return Command::SUCCESS;
    }
}
