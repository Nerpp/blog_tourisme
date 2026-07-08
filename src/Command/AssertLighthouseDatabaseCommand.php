<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:test-database:assert-safe',
    description: 'Refuse toute préparation de tests hors de la base app_test.',
    aliases: ['app:lighthouse:assert-safe-database'],
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
        $database = $this->databaseNameFromConnection();

        if ($environment !== self::EXPECTED_ENVIRONMENT || $database !== self::EXPECTED_DATABASE) {
            $output->writeln(sprintf(
                '<error>Refus de préparer les tests : environnement "%s", base "%s". Attendu : environnement "%s", base "%s".</error>',
                $environment,
                $database ?? '(inconnue)',
                self::EXPECTED_ENVIRONMENT,
                self::EXPECTED_DATABASE,
            ));
            $output->writeln('<error>La base de développement ne doit jamais être préparée par les outils de test.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Base de test sûre confirmée : environnement %s, base %s.</info>',
            self::EXPECTED_ENVIRONMENT,
            self::EXPECTED_DATABASE,
        ));

        return Command::SUCCESS;
    }

    private function databaseNameFromConnection(): ?string
    {
        $params = $this->connection->getParams();

        if (isset($params['dbname']) && is_string($params['dbname']) && $params['dbname'] !== '') {
            return $params['dbname'];
        }

        if (!isset($params['url']) || !is_string($params['url']) || $params['url'] === '') {
            return null;
        }

        $path = parse_url($params['url'], PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $database = ltrim($path, '/');

        return $database !== '' ? $database : null;
    }
}
