<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-monolog-email',
    description: 'Déclenche un log error contrôlé pour tester l’alerte email Monolog.',
)]
final class TestMonologEmailCommand extends Command
{
    private const NAME = 'app:test-monolog-email';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->logger->error('Test contrôlé de l’alerte email Monolog.', [
            'command' => self::NAME,
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $io->success('Log error de test envoyé à Monolog. En prod, le handler email doit être déclenché si MAILER_DSN pointe vers un vrai SMTP.');

        return Command::SUCCESS;
    }
}
