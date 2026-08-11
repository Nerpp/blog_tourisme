<?php

namespace App\Command;

use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramPublicationScheduler;
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
    private const int DEFAULT_LIMIT = 50;
    private const int DEFAULT_PENDING_AGE_SECONDS = 300;
    private const int DEFAULT_PROCESSING_AGE_SECONDS = 1800;

    public function __construct(
        private readonly InstagramPublicationRepository $publicationRepository,
        private readonly InstagramPublicationScheduler $publicationScheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de tâches reprogrammées.', (string) self::DEFAULT_LIMIT)
            ->addOption('pending-age', null, InputOption::VALUE_REQUIRED, 'Âge minimal en secondes d’une tâche pending.', (string) self::DEFAULT_PENDING_AGE_SECONDS)
            ->addOption('processing-age', null, InputOption::VALUE_REQUIRED, 'Âge minimal en secondes d’une tâche processing considérée abandonnée.', (string) self::DEFAULT_PROCESSING_AGE_SECONDS);
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

        $now = new DateTimeImmutable();
        $pendingCutoff = $now->modify(sprintf('-%d seconds', $pendingAge));
        $processingCutoff = $now->modify(sprintf('-%d seconds', $processingAge));
        $publications = $this->publicationRepository->findRecoverableForDispatch(
            $pendingCutoff,
            $processingCutoff,
            min($limit, 500),
        );

        $dispatched = 0;
        foreach ($publications as $publication) {
            $publicationId = $publication->getId();
            if ($publicationId !== null && $this->publicationScheduler->recoverAndDispatch(
                $publicationId,
                $pendingCutoff,
                $processingCutoff,
            )) {
                ++$dispatched;
            }
        }

        $io->success(sprintf(
            '%d publication(s) Instagram reprogrammée(s) sur %d candidate(s).',
            $dispatched,
            count($publications),
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
