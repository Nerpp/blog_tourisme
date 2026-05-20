<?php

namespace App\Command;

use App\Repository\TrafficEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:traffic:prune',
    description: 'Supprime les événements de trafic anonymisés trop anciens.',
)]
final class PruneTrafficEventsCommand extends Command
{
    public function __construct(
        private readonly TrafficEventRepository $trafficEventRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Nombre de jours à conserver.', '180')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le volume concerné sans supprimer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');
        $threshold = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days));
        $count = $this->trafficEventRepository->pruneOlderThan($threshold, true);

        if ($dryRun) {
            $io->success(sprintf('%d événement(s) seraient supprimés avant le %s.', $count, $threshold->format('d/m/Y')));

            return Command::SUCCESS;
        }

        if ($input->isInteractive() && !$io->confirm(sprintf('Supprimer définitivement %d événement(s) avant le %s ?', $count, $threshold->format('d/m/Y')), false)) {
            $io->note('Purge annulée.');

            return Command::SUCCESS;
        }

        $count = $this->trafficEventRepository->pruneOlderThan($threshold, false);

        $io->success(sprintf('%d événement(s) supprimés avant le %s.', $count, $threshold->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
