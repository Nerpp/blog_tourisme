<?php

namespace App\Command;

use App\Repository\TrafficEventRepository;
use InvalidArgumentException;
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
        try {
            $days = $this->positiveIntOption($input->getOption('days'), '--days');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

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

    private function positiveIntOption(mixed $value, string $option): int
    {
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }

            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        $value = trim($value);
        $digits = ltrim($value, '0');
        if (!ctype_digit($value) || $digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
        ) {
            throw new InvalidArgumentException(sprintf('L’option %s doit être un entier strictement positif.', $option));
        }

        return (int) $value;
    }
}
