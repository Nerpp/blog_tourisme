<?php

namespace App\Command;

use App\Service\Instagram\InstagramPublicationReconciler;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:instagram:dispatch-pending',
    description: 'Reprogramme les publications Instagram en attente ou abandonnées par un worker interrompu.',
)]
final class DispatchPendingInstagramPublicationsCommand extends Command
{
    public function __construct(
        private readonly InstagramPublicationReconciler $publicationReconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de tâches reprogrammées.', (string) InstagramPublicationReconciler::DEFAULT_LIMIT)
            ->addOption('pending-age', null, InputOption::VALUE_REQUIRED, 'Âge minimal en secondes d’une tâche pending.', (string) InstagramPublicationReconciler::DEFAULT_PENDING_AGE_SECONDS)
            ->addOption('processing-age', null, InputOption::VALUE_REQUIRED, 'Âge minimal en secondes d’une tâche processing considérée abandonnée.', (string) InstagramPublicationReconciler::DEFAULT_PROCESSING_AGE_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $this->positiveIntegerOption($input, 'limit');
        $pendingAge = $this->positiveIntegerOption($input, 'pending-age');
        $processingAge = $this->positiveIntegerOption($input, 'processing-age');

        if ($limit === null || $pendingAge === null || $processingAge === null) {
            $io->error('Les options limit, pending-age et processing-age doivent être des entiers strictement positifs.');

            return Command::INVALID;
        }

        $result = $this->publicationReconciler->reconcile(
            new DateTimeImmutable(),
            $pendingAge,
            $processingAge,
            $limit,
        );
        if ($result->skippedDueToLock) {
            $io->note('Une réconciliation Instagram est déjà en cours. Aucun doublon n’a été programmé.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d publication(s) Instagram reprogrammée(s) sur %d candidate(s).',
            $result->dispatchedCount,
            $result->candidateCount,
        ));

        return Command::SUCCESS;
    }

    private function positiveIntegerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
